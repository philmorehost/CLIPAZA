<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$defaults = [
    'lp_hero_title' => 'Where Creators Reward',
    'lp_hero_accent' => 'Their Biggest Fans.',
    'lp_hero_sub' => 'Pick a contest from your favourite YouTube creator. Clip their best moment, post it on TikTok or Reels, and let the views decide who wins.',
    'lp_hero_btn_creator' => 'Start a Contest →',
    'lp_hero_btn_fan' => 'Join as a Fan →',
    'lp_hiw_title' => 'Works',
    'lp_brands_title' => 'Where Creators and Brands Rewards their biggest fans',
    'lp_brands_sub' => 'Pick a contest from their favorite youtube creators/favorite musician/favorite artist/favorite brands. Clip their best moments, post it on tiktok reals or shorts and let the views decides who wins.',
    'lp_brands_content' => 'Clipaza connects fans with their idols. Whether it\'s a breakout musician, a top-tier YouTube creator, or a global brand, you can now turn your fandom into rewards. Find an active contest, showcase your editing skills, and win real cash while helping your favorites grow.',
    'lp_features_title' => 'Clipaza',
    'lp_features_sub' => 'Everything a creator or fan needs — nothing they don\'t',
    'lp_creators_title' => 'Your fans will promote you better than any ad ever will.',
    'lp_creators_sub' => 'They already know your content. They already have opinions about it. Clipaza gives them a contest to enter and a prize to chase — and the side effect is your video spreading across three platforms at once, carried by people who genuinely like what you make.',
    'lp_creators_extra' => 'You set the prize. You pick the video. You decide how long the contest runs. After that, your fans handle the rest.',
    'lp_creators_p1' => 'Any budget works — you set the number',
    'lp_creators_p2' => 'Live dashboard showing every clip submitted',
    'lp_creators_p3' => 'Your video hits TikTok and Reels simultaneously',
    'lp_creators_p4' => 'Flag any clip that doesn\'t fit your brand',
    'lp_fans_title' => 'You were going to watch it. Might as well win something.',
    'lp_fans_sub' => 'Find a contest for a creator you follow. Watch the video. Pull out the clip that nobody else will think to cut. Post it. Then check the leaderboard obsessively for the next two weeks like the rest of us.',
    'lp_fans_extra' => 'No fancy equipment. No editing degree. Just a good eye and a phone that works.',
    'lp_fans_p1' => 'Runs on TikTok and Instagram Reels',
    'lp_fans_p2' => 'Submit as many clips as contests allow',
    'lp_fans_p3' => 'Leaderboard updates in real time so you always know where you stand',
    'lp_fans_p4' => 'Cash goes straight to your bank when you win',
    'lp_lb_title_accent' => 'Win Here.',
    'lp_lb_text' => 'Every submitted link gets tracked across TikTok and Reels — but only authentic views count. No purchased traffic. No bot plays. No inflated numbers. If real people watched it, it counts. If they didn\'t, it doesn\'t. No judges, no panels, no back-room decisions. Contest closes and the honest number at the top wins.',
    'lp_cta_title' => 'Creator or fan — there\'s a spot for you.',
    'lp_cta_sub' => 'Contests are live right now. Sign up free and see what\'s running.',
    'lp_f1_title' => 'Any budget works',
    'lp_f1_desc' => 'You set the prize. Contests work at any prize level — from a quick boost to a serious campaign. You decide how much and how long.',
    'lp_f2_title' => 'Live dashboard',
    'lp_f2_desc' => 'Every clip submitted sits on a live leaderboard. View counts update in real time so you — and your fans — always know where things stand.',
    'lp_f3_title' => 'Multiple platforms at once',
    'lp_f3_desc' => 'Your video hits TikTok and Instagram Reels simultaneously — carried by people who genuinely like what you make.',
    'lp_f4_title' => 'Brand control',
    'lp_f4_desc' => 'Flag any clip that doesn\'t fit your brand. You stay in control of what\'s associated with your channel throughout the contest.',
    'lp_f5_title' => 'Cash to your bank',
    'lp_f5_desc' => 'Winners get paid directly to their bank account. No gift cards, no vouchers, no waiting — just a bank transfer when the contest closes.',
    'lp_f6_title' => 'Only real views win',
    'lp_f6_desc' => 'No purchased traffic. No bot plays. No inflated numbers. If real people watched it, it counts. If they didn\'t, it doesn\'t.',
    'lp_step1_title' => 'Creator launches a contest',
    'lp_step1_desc' => 'They pick a video, set a prize, and open it to their fanbase. The contest goes live on Clipaza and anyone can join.',
    'lp_step2_title' => 'Fans clip and post',
    'lp_step2_desc' => 'You find the moment that\'s going to stop people mid-scroll. Cut it, post it wherever you\'re strongest — TikTok or Reels — then drop the link.',
    'lp_step3_title' => 'Views decide the winner',
    'lp_step3_desc' => 'Every clip sits on a live leaderboard. Watch your rank move in real time. When the contest closes, whoever has the most views takes the money home.',
    'lp_trending_title_accent' => 'Contests'
];

$db = db();
$stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = IF(setting_value = '', VALUES(setting_value), setting_value)");

foreach ($defaults as $key => $value) {
    $stmt->execute([$key, $value]);
}

echo "Settings fixed. Please refresh your admin panel.";
