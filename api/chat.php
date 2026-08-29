<?php
require __DIR__ . '/_lib.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    ns_json_response(array('error' => 'method_not_allowed'), 405);
}

$body = ns_read_input();
$message = isset($body['message']) ? trim((string) $body['message']) : '';
$history = isset($body['history']) && is_array($body['history']) ? $body['history'] : array();

if ($message === '') {
    ns_json_response(array('error' => 'empty_message'), 400);
}
$message = ns_utf8_safe_truncate($message, 1200);

// ---- rate limiting: protects the free Gemini quota from being drained by
// a single abusive visitor or a traffic spike -- both a per-IP cap and a
// site-wide daily cap, reset at UTC midnight, tracked in one small guarded
// file under the same atomic-lock pattern as the rest of the API.
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$today = gmdate('Y-m-d');
$IP_DAILY_CAP = 40;
$SITE_DAILY_CAP = 600;

$counts = ns_locked_update('chat_rate.php', array(), function ($data) use ($ip, $today) {
    if (!is_array($data) || !isset($data['day']) || $data['day'] !== $today) {
        $data = array('day' => $today, 'total' => 0, 'ips' => array());
    }
    $data['total'] = (isset($data['total']) ? $data['total'] : 0) + 1;
    $data['ips'][$ip] = (isset($data['ips'][$ip]) ? $data['ips'][$ip] : 0) + 1;
    // Keep the ip map from growing unbounded over a busy day.
    if (count($data['ips']) > 5000) {
        $data['ips'] = array_slice($data['ips'], -1000, null, true);
    }
    return $data;
});
if ($counts === false) {
    ns_json_response(array('error' => 'server_error'), 500);
}
if ($counts['total'] > $SITE_DAILY_CAP || $counts['ips'][$ip] > $IP_DAILY_CAP) {
    ns_json_response(array('error' => 'rate_limited', 'reply' => array(
        'bn' => 'আজকের জন্য চ্যাট সীমা শেষ হয়ে গেছে। অনুগ্রহ করে WhatsApp বা ফোনে যোগাযোগ করুন, অথবা কাল আবার চেষ্টা করুন।',
        'en' => "Today's chat limit has been reached. Please reach us on WhatsApp/phone, or try again tomorrow.",
    )), 429);
}

$configPath = NS_DATA_DIR . '/config.php';
$config = file_exists($configPath) ? include $configPath : array();
$apiKey = isset($config['gemini_api_key']) ? (string) $config['gemini_api_key'] : '';
if ($apiKey === '') {
    ns_json_response(array('error' => 'not_configured'), 500);
}

function ns_chat_bi($x) {
    if ($x === null) return '';
    if (is_string($x)) return $x;
    if (isset($x['bn']) && $x['bn'] !== '') return $x['bn'];
    if (isset($x['en'])) return $x['en'];
    return '';
}

$content = ns_read_guarded_json('content.php', array());
if (!is_array($content)) $content = array();

// ---- same real, live business data the public site itself renders from
// (services/packages/prices/FAQ/contact) -- mirrors the defaults and
// override pattern in index.php so the bot can never drift out of sync
// with what's actually shown/charged on the site.
$defServices = array(
    'arch' => array('title' => 'স্থাপত্য পরামর্শ', 'desc' => 'বাড়ি, ভবন, রুম বিন্যাস, আলো-বাতাস, ফ্যাসাড, সংস্কার ও জায়গার সর্বোত্তম ব্যবহার।'),
    'struct' => array('title' => 'স্ট্রাকচারাল পরামর্শ', 'desc' => 'কলাম, বিম, ফাউন্ডেশন, ফাটল, পুরোনো ভবন, ভূমিকম্প নিরাপত্তা ও কাঠামোগত সমস্যা।'),
    'elec' => array('title' => 'ইলেকট্রিক্যাল পরামর্শ', 'desc' => 'লোড পরিকল্পনা, আলো, পাওয়ার পয়েন্ট, DB, নিরাপত্তা ও বৈদ্যুতিক লেআউট।'),
    'plumb' => array('title' => 'প্লাম্বিং পরামর্শ', 'desc' => 'পানি সরবরাহ, ড্রেনেজ, স্যানিটারি, সেপটিক ট্যাংক, রেইন ওয়াটার ও পাইপ লেআউট।'),
    'interior' => array('title' => 'ইন্টেরিয়র পরামর্শ', 'desc' => 'ইন্টেরিয়র লেআউট, রঙ নির্বাচন, আসবাবপত্র বিন্যাস, লাইটিং ও ফিনিশিং নিয়ে পেশাদার পরামর্শ।'),
);
$services = isset($content['services']) && is_array($content['services']) ? $content['services'] : array();
$servicesText = '';
foreach ($defServices as $k => $def) {
    $s = isset($services[$k]) ? $services[$k] : array('title' => array('bn' => $def['title']), 'desc' => array('bn' => $def['desc']));
    $servicesText .= '- ' . ns_chat_bi(isset($s['title']) ? $s['title'] : $def['title']) . ': ' . ns_chat_bi(isset($s['desc']) ? $s['desc'] : $def['desc']) . "\n";
}

$defaultPrices = array('quick' => 800, 'detail' => 1500, 'project' => 3500, 'site_dhk' => 5000, 'site_out' => 8000);
$prices = array_merge($defaultPrices, (isset($content['prices']) && is_array($content['prices'])) ? $content['prices'] : array());
$pkgDurOverrides = isset($content['pkg_dur']) && is_array($content['pkg_dur']) ? $content['pkg_dur'] : array();
$pkgs = array(
    array('id' => 'quick', 'name' => 'দ্রুত পরামর্শ', 'dur' => '২০ মিনিট'),
    array('id' => 'detail', 'name' => 'বিস্তারিত পরামর্শ', 'dur' => '৪৫ মিনিট'),
    array('id' => 'project', 'name' => 'প্রকল্প পরামর্শ', 'dur' => '৬০–৯০ মিনিট'),
    array('id' => 'site_dhk', 'name' => 'সাইট ভিজিট পরামর্শ', 'dur' => 'ঢাকার মধ্যে'),
    array('id' => 'site_out', 'name' => 'সাইট ভিজিট পরামর্শ', 'dur' => 'ঢাকার বাইরে'),
);
$pkgsText = '';
foreach ($pkgs as $pk) {
    $dur = isset($pkgDurOverrides[$pk['id']]) ? ns_chat_bi($pkgDurOverrides[$pk['id']]) : $pk['dur'];
    $price = isset($prices[$pk['id']]) ? $prices[$pk['id']] : 0;
    $pkgsText .= '- ' . $pk['name'] . ' (' . $dur . '): ৳' . number_format($price) . "\n";
}

$defFaq = array(
    array('q' => 'অনলাইন পরামর্শে কী কী সমস্যা সমাধান করা যায়?', 'a' => 'নকশা পর্যালোচনা, রুম বিন্যাস, ফাটল বা কাঠামোগত উদ্বেগ, বৈদ্যুতিক ও প্লাম্বিং পরিকল্পনা, সংস্কার এবং জায়গার ব্যবহার — বেশিরভাগ বিষয়েই দিকনির্দেশনা দেওয়া যায়। জটিল ক্ষেত্রে সাইট ভিজিট প্রয়োজন হতে পারে।'),
    array('q' => 'কোন ড্রয়িং আগে পাঠাতে হবে?', 'a' => 'যা আছে তা-ই যথেষ্ট — সাইটের ছবি, হাতে আঁকা স্কেচ, পুরোনো প্ল্যান বা PDF ড্রয়িং।'),
    array('q' => 'জমির কাগজ প্রয়োজন কি?', 'a' => 'পরামর্শের জন্য সাধারণত প্রয়োজন নেই। শুধু জমির পরিমাণ ও অবস্থান জানালেই চলে।'),
    array('q' => 'পরামর্শের পর ড্রয়িং পাওয়া যাবে কি?', 'a' => 'প্রযোজ্য ক্ষেত্রে মার্কআপ ড্রয়িং, স্কেচ বা লিখিত সারাংশ ড্যাশবোর্ডে দেওয়া হয়। পূর্ণ ডিজাইন একটি আলাদা প্রকল্প সেবা।'),
    array('q' => 'সাইট ভিজিট প্রয়োজন হলে কী হবে?', 'a' => 'প্রয়োজন হলে জানিয়ে দেওয়া হয় এবং আলাদা ব্যবস্থা করা হয়। অনলাইন পরামর্শ বাধ্যতামূলক সরেজমিন পরিদর্শনের বিকল্প নয়।'),
    array('q' => 'পেমেন্ট ফেরতযোগ্য কি?', 'a' => 'নির্ধারিত সময়ের আগে বাতিল করলে পুনঃনির্ধারণ করা যায়। বিশেষ ক্ষেত্রে ফেরত প্রযোজ্য হতে পারে।'),
    array('q' => 'সময় পরিবর্তন করা যাবে কি?', 'a' => 'হ্যাঁ, মিটিংয়ের নির্ধারিত সময়ের যথেষ্ট আগে ড্যাশবোর্ড থেকে সময় পরিবর্তন করা যায়।'),
    array('q' => 'Zoom ব্যবহার না জানলে কী করব?', 'a' => 'চিন্তার কিছু নেই — WhatsApp-এ কল করেও পরামর্শ নেওয়া যায়।'),
);
$faq = isset($content['faq']) && is_array($content['faq']) ? $content['faq'] : $defFaq;
$faqText = '';
foreach ($faq as $f) {
    $q = is_array($f) && isset($f['q']) ? ns_chat_bi($f['q']) : '';
    $a = is_array($f) && isset($f['a']) ? ns_chat_bi($f['a']) : '';
    if ($q === '' && isset($f['q']) && is_string($f['q'])) { $q = $f['q']; $a = isset($f['a']) ? $f['a'] : ''; }
    if ($q !== '') $faqText .= '- প্রশ্ন: ' . $q . ' | উত্তর: ' . $a . "\n";
}

$defContact = array('wa' => '8801711034941', 'call' => '+8801711034941', 'email' => 'arch_cell@yahoo.com');
$contact = isset($content['contact']) && is_array($content['contact']) ? $content['contact'] : $defContact;

$systemPrompt = "আপনি নকশা সেবা (Noksha Seba) ওয়েবসাইটের একজন সহায়ক গ্রাহকসেবা সহকারী। নকশা সেবা বাংলাদেশে অনলাইন স্থাপত্য, স্ট্রাকচারাল, ইলেকট্রিক্যাল, প্লাম্বিং ও ইন্টেরিয়র পরামর্শ প্রদান করে (WhatsApp/Zoom-এ)।\n\n"
    . "নিয়ম:\n"
    . "- ভিজিটর বাংলায় লিখলে বাংলায়, ইংরেজিতে লিখলে ইংরেজিতে উত্তর দিন।\n"
    . "- উত্তর সংক্ষিপ্ত ও স্পষ্ট রাখুন (সাধারণত ২-৪ বাক্য; তালিকার প্রয়োজনে বুলেট পয়েন্ট ব্যবহার করুন)।\n"
    . "- নিচে দেওয়া প্রকৃত তথ্য (সেবা, প্যাকেজ, মূল্য, FAQ, যোগাযোগ) ছাড়া কোনো দাম বা তথ্য নিজে থেকে বানাবেন না।\n"
    . "- বুকিং করতে চাইলে ওয়েবসাইটের 'সেবা নিন'/বুকিং ফ্লো ব্যবহার করতে বলুন, অথবা WhatsApp/ফোনে যোগাযোগের কথা বলুন।\n"
    . "- এটি প্রাথমিক পরামর্শ সেবা, প্রয়োজনীয় সরেজমিন পরিদর্শন বা বিস্তারিত ইঞ্জিনিয়ারিং ডিজাইনের বিকল্প নয় -- গুরুতর কাঠামোগত ঝুঁকি/জরুরি অবস্থায় সংশ্লিষ্ট কর্তৃপক্ষের সাথে যোগাযোগ করতে বলুন।\n"
    . "- এই ব্যবসার সাথে সম্পর্কহীন প্রশ্নে ভদ্রভাবে জানিয়ে দিন যে আপনি শুধু নকশা সেবা সম্পর্কে সাহায্য করতে পারেন।\n\n"
    . "সেবাসমূহ:\n" . $servicesText . "\n"
    . "প্যাকেজ ও মূল্য:\n" . $pkgsText . "\n"
    . "সচরাচর জিজ্ঞাসা:\n" . $faqText . "\n"
    . "যোগাযোগ: WhatsApp/ফোন " . (isset($contact['call']) ? $contact['call'] : '') . ", ইমেইল " . (isset($contact['email']) ? $contact['email'] : '') . "\n"
    . "ওয়েবসাইট: https://nokshaseba.com/";

// Trim history to the last few turns and coerce into Gemini's contents shape.
$contents = array();
$historySlice = array_slice($history, -8);
foreach ($historySlice as $turn) {
    if (!is_array($turn) || empty($turn['text'])) continue;
    $role = (isset($turn['role']) && $turn['role'] === 'model') ? 'model' : 'user';
    $text = ns_utf8_safe_truncate((string) $turn['text'], 1200);
    $contents[] = array('role' => $role, 'parts' => array(array('text' => $text)));
}
$contents[] = array('role' => 'user', 'parts' => array(array('text' => $message)));

$payload = array(
    'contents' => $contents,
    'systemInstruction' => array('parts' => array(array('text' => $systemPrompt))),
    'generationConfig' => array('maxOutputTokens' => 500, 'temperature' => 0.4),
);

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-lite-latest:generateContent?key=' . rawurlencode($apiKey);
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 25,
));
$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($raw === false || $httpCode >= 400) {
    ns_json_response(array('error' => 'upstream_failed', 'detail' => $curlErr ?: $raw), 502);
}

$decoded = json_decode($raw, true);
$reply = '';
if (isset($decoded['candidates'][0]['content']['parts']) && is_array($decoded['candidates'][0]['content']['parts'])) {
    foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) $reply .= $part['text'];
    }
}
$reply = trim($reply);
if ($reply === '') {
    ns_json_response(array('error' => 'empty_reply'), 502);
}

ns_json_response(array('reply' => $reply));
