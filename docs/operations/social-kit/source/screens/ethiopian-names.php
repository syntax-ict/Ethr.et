<?php

// Gives the demo organisation's staff Ethiopian names, for screenshots only.
//
//   php docs/operations/social-kit/source/screens/ethiopian-names.php
//
// DemoTenantSeeder names its 150 employees with Faker's en_US locale, so a
// screenshot of an Ethiopian product shows "Delbert Kuhn". This rewrites the
// names of the demo tenant's employees, in English and Amharic, in whatever
// database api/.env points at. Run it against a local database seeded for
// screenshots, never a real one: it refuses any tenant but `demo`.
//
// Every name is a given name and a father's name, the Ethiopian convention.
// The pairs are generated, so no row names a real person.

declare(strict_types=1);

$api = dirname(__DIR__, 5).'/api';
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$male = [
    ['Abebe', 'አበበ'], ['Dawit', 'ዳዊት'], ['Yohannes', 'ዮሐንስ'], ['Tesfaye', 'ተስፋዬ'],
    ['Bereket', 'በረከት'], ['Henok', 'ሄኖክ'], ['Samuel', 'ሳሙኤል'], ['Mulugeta', 'ሙሉጌታ'],
    ['Getachew', 'ጌታቸው'], ['Solomon', 'ሰለሞን'], ['Biniyam', 'ቢንያም'], ['Kebede', 'ከበደ'],
    ['Tadesse', 'ታደሰ'], ['Yonas', 'ዮናስ'], ['Ermias', 'ኤርሚያስ'], ['Fikadu', 'ፍቃዱ'],
    ['Girma', 'ግርማ'], ['Mesfin', 'መስፍን'], ['Natnael', 'ናትናኤል'], ['Elias', 'ኤልያስ'],
];
$female = [
    ['Almaz', 'አልማዝ'], ['Hirut', 'ሂሩት'], ['Meron', 'ሜሮን'], ['Selam', 'ሰላም'],
    ['Tigist', 'ትግስት'], ['Hanna', 'ሐና'], ['Bethlehem', 'ቤተልሔም'], ['Mahlet', 'ማህሌት'],
    ['Saba', 'ሳባ'], ['Rahel', 'ራሔል'], ['Liya', 'ሊያ'], ['Eden', 'ኤደን'],
    ['Martha', 'ማርታ'], ['Senait', 'ሰናይት'], ['Tsion', 'ጽዮን'], ['Kidist', 'ቅድስት'],
    ['Aster', 'አስቴር'], ['Helen', 'ሄለን'], ['Mekdes', 'መቅደስ'], ['Frehiwot', 'ፍሬሕይወት'],
];
$fathers = array_merge($male, [
    ['Alemu', 'አለሙ'], ['Bekele', 'በቀለ'], ['Worku', 'ወርቁ'], ['Desta', 'ደስታ'],
    ['Asfaw', 'አስፋው'], ['Negash', 'ነጋሽ'], ['Mekonnen', 'መኮንን'], ['Tefera', 'ተፈራ'],
    ['Ayele', 'አየለ'], ['Lemma', 'ለማ'], ['Haile', 'ኃይሌ'], ['Wolde', 'ወልዴ'],
]);

$tenantId = DB::table('tenants')->where('subdomain', 'demo')->value('id');
if ($tenantId === null) {
    fwrite(STDERR, "No tenant with subdomain 'demo'. Seed DemoTenantSeeder first.\n");
    exit(1);
}

$rows = DB::table('employees')->where('tenant_id', $tenantId)->orderBy('id')->get(['id', 'gender']);
$counts = ['male' => 0, 'female' => 0];
foreach ($rows as $row) {
    $sex = $row->gender === 'female' ? 'female' : 'male';
    $n = $counts[$sex]++;
    $list = $sex === 'female' ? $female : $male;
    [$given, $givenAm] = $list[$n % count($list)];
    // Step the father's name by a different stride so pairs do not repeat.
    [$father, $fatherAm] = $fathers[intdiv($n, count($list)) * 7 % count($fathers) + $n % 3];
    if ($father === $given) {
        [$father, $fatherAm] = $fathers[($n + 11) % count($fathers)];
    }
    DB::table('employees')->where('tenant_id', $tenantId)->where('id', $row->id)->update([
        'name' => "$given $father",
        'name_am' => "$givenAm $fatherAm",
        // The seeder's Faker addresses do not match the new names.
        'email' => strtolower("$given.$father").'@example.com',
    ]);
}

// The admin accounts have no employee record; their display name is what the
// header shows.
DB::table('users')->where('tenant_id', $tenantId)->where('email', 'admin@demo.ethr.et')->update(['display_name' => 'Hirut Bekele']);
DB::table('users')->where('tenant_id', $tenantId)->where('email', 'hr@demo.ethr.et')->update(['display_name' => 'Dawit Alemu']);

echo count($rows)." demo employees renamed.\n";
