<?php

/**
 * Dresses the seeded demo organisation for the Amharic user guides.
 *
 * Run it against a scratch database that `migrate:fresh --seed` has just
 * filled, never against one anyone uses:
 *
 *   DB_DATABASE=ethr_guide php api/artisan migrate:fresh --seed --force
 *   DB_DATABASE=ethr_guide php scripts/user-guide/prepare-data.php
 *
 * The seeder names people with Faker's English names. A guide written for
 * Ethiopian readers shows Ethiopian names, in both scripts, a fictional
 * organisation ("ናሙና", sample), one supervisor with a team, and accounts
 * that read and date in Amharic and the Ethiopian calendar. Addresses are
 * on sample.ethr.et, a name the product owns, so no screenshot shows an
 * address that belongs to someone else.
 *
 * Names go into both columns in Amharic, `name` as well as `name_am`: a few
 * screens still print the English column whatever the reader's language,
 * and a guide in Amharic should not show them in English.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$root = getenv('ETHR_API_ROOT') ?: dirname(__DIR__, 2).'/api';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();
if (in_array($database, ['ethr', 'ethr_local_prod'], true) || app()->isProduction()) {
    fwrite(STDERR, "Refusing to dress '{$database}': point DB_DATABASE at a scratch database.\n");
    exit(1);
}

$tenant = DB::table('tenants')->where('subdomain', 'demo')->first();
if ($tenant === null) {
    fwrite(STDERR, "No demo organisation in '{$database}'. Run migrate:fresh --seed first.\n");
    exit(1);
}
$tenantId = (int) $tenant->id;
$domain = 'sample.ethr.et';

$male = [
    ['Abebe', 'አበበ'], ['Dawit', 'ዳዊት'], ['Yonas', 'ዮናስ'], ['Solomon', 'ሰሎሞን'],
    ['Mulugeta', 'ሙሉጌታ'], ['Bereket', 'በረከት'], ['Henok', 'ሄኖክ'], ['Samuel', 'ሳሙኤል'],
    ['Kidus', 'ቅዱስ'], ['Getachew', 'ጌታቸው'], ['Biniam', 'ቢኒያም'], ['Nahom', 'ናሆም'],
    ['Eyob', 'ኢዮብ'], ['Fitsum', 'ፍፁም'], ['Tewodros', 'ቴዎድሮስ'], ['Yared', 'ያሬድ'],
    ['Abel', 'አቤል'], ['Mesfin', 'መስፍን'], ['Daniel', 'ዳንኤል'], ['Wondimu', 'ወንድሙ'],
];
$female = [
    ['Selam', 'ሰላም'], ['Almaz', 'አልማዝ'], ['Hirut', 'ሂሩት'], ['Meron', 'ሜሮን'],
    ['Tigist', 'ትዕግሥት'], ['Bethlehem', 'ቤተልሔም'], ['Hana', 'ሐና'], ['Mahlet', 'ማኅሌት'],
    ['Rahel', 'ራሔል'], ['Sara', 'ሳራ'], ['Eden', 'ኤደን'], ['Liya', 'ሊያ'],
    ['Mekdes', 'መቅደስ'], ['Saba', 'ሳባ'], ['Tsion', 'ጽዮን'], ['Yordanos', 'ዮርዳኖስ'],
    ['Azeb', 'አዜብ'], ['Genet', 'ገነት'], ['Kalkidan', 'ቃልኪዳን'], ['Lidya', 'ልድያ'],
];
$fathers = [
    ['Kebede', 'ከበደ'], ['Tesfaye', 'ተስፋዬ'], ['Bekele', 'በቀለ'], ['Alemu', 'ዓለሙ'],
    ['Girma', 'ግርማ'], ['Haile', 'ኃይሌ'], ['Mengistu', 'መንግሥቱ'], ['Tadesse', 'ታደሰ'],
    ['Wolde', 'ወልዴ'], ['Assefa', 'አሰፋ'], ['Desta', 'ደስታ'], ['Mulatu', 'ሙላቱ'],
    ['Gebre', 'ገብሬ'], ['Abate', 'አባተ'], ['Lemma', 'ለማ'], ['Negash', 'ነጋሽ'],
    ['Worku', 'ወርቁ'], ['Ayele', 'አየለ'], ['Shiferaw', 'ሽፈራው'], ['Mekonnen', 'መኮንን'],
];

// The four people the guides follow, then everyone else. Each pair is used once.
$cast = [
    'employee' => ['female', ['Selam', 'ሰላም'], ['Tesfaye', 'ተስፋዬ']],
    'supervisor' => ['male', ['Dawit', 'ዳዊት'], ['Mengistu', 'መንግሥቱ']],
    'hr' => ['female', ['Hirut', 'ሂሩት'], ['Alemu', 'ዓለሙ']],
    'admin' => ['female', ['Meron', 'ሜሮን'], ['Kebede', 'ከበደ']],
];
$used = [];
foreach ($cast as [, $first, $father]) {
    $used[$first[0].' '.$father[0]] = true;
}

$employees = DB::table('employees')->where('tenant_id', $tenantId)->orderBy('id')->get(['id', 'gender', 'department_id']);
$names = [];
$i = 0;
foreach ($employees as $employee) {
    $pool = $employee->gender === 'female' ? $female : $male;
    do {
        $first = $pool[$i % count($pool)];
        $father = $fathers[intdiv($i, count($pool)) % count($fathers)];
        $i++;
    } while (isset($used[$first[0].' '.$father[0]]));
    $used[$first[0].' '.$father[0]] = true;
    $names[$employee->id] = [$first, $father];
}

$castIds = array_slice($employees->pluck('id')->all(), 0, 4);
foreach (array_values($cast) as $n => [$gender, $first, $father]) {
    $names[$castIds[$n]] = [$first, $father];
    DB::table('employees')->where('tenant_id', $tenantId)->where('id', $castIds[$n])->update(['gender' => $gender]);
}

foreach ($names as $id => [$first, $father]) {
    $local = strtolower($first[0].'.'.$father[0]);
    DB::table('employees')->where('tenant_id', $tenantId)->where('id', $id)->update([
        'name' => $first[1].' '.$father[1],
        'name_am' => $first[1].' '.$father[1],
        'email' => $local.'@'.$domain,
    ]);
}

$departments = [
    'Engineering' => 'ምህንድስና',
    'Finance' => 'ፋይናንስ',
    'Human Resources' => 'የሰው ሀብት',
    'Operations' => 'ኦፕሬሽን',
    'Sales' => 'ሽያጭ',
];
foreach ($departments as $en => $am) {
    DB::table('departments')->where('tenant_id', $tenantId)->where('name', $en)->update(['name' => $am, 'name_am' => $am]);
}

$positions = [
    'Developer' => 'ሶፍትዌር ገንቢ',
    'Accountant' => 'ሒሳብ ባለሙያ',
    'HR Officer' => 'የሰው ሀብት ኦፊሰር',
    'Manager' => 'ሥራ አስኪያጅ',
    'Sales Rep' => 'የሽያጭ ባለሙያ',
];
foreach ($positions as $en => $am) {
    DB::table('positions')->where('tenant_id', $tenantId)->where('title', $en)->update(['title' => $am, 'title_am' => $am]);
}

DB::table('branches')->where('tenant_id', $tenantId)->where('code', 'HQ')
    ->update(['name' => 'አዲስ አበባ ዋና መሥሪያ ቤት', 'name_am' => 'አዲስ አበባ ዋና መሥሪያ ቤት']);

$leaveTypes = [
    'Annual Leave' => 'የዓመት ፈቃድ',
    'Sick Leave' => 'የሕመም ፈቃድ',
];
foreach ($leaveTypes as $en => $am) {
    DB::table('leave_types')->where('tenant_id', $tenantId)->where('name', $en)->update(['name' => $am, 'name_am' => $am]);
}

DB::table('holidays')->where('tenant_id', $tenantId)->whereNotNull('name_am')->update(['name' => DB::raw('name_am')]);
DB::table('leave_types')->where('tenant_id', $tenantId)->whereNotNull('name_am')->update(['name' => DB::raw('name_am')]);
DB::table('shifts')->where('tenant_id', $tenantId)->where('name', 'Regular')->update(['name' => 'መደበኛ']);

$settings = json_decode((string) $tenant->settings, true) ?: [];
$settings['locale'] = 'am';
$settings['calendar'] = 'ethiopian';
DB::table('tenants')->where('id', $tenantId)->update([
    'name' => 'ናሙና ድርጅት',
    'default_locale' => 'am',
    'ethiopian_calendar' => true,
    'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
]);

// The supervisor leads eight people in the employee's department, the
// employee among them, so the manager guide has a team to show.
$employeeId = $castIds[0];
$supervisorId = $castIds[1];
$department = DB::table('employees')->where('id', $employeeId)->value('department_id');
DB::table('employees')->where('tenant_id', $tenantId)->whereIn('id', [$employeeId, $supervisorId])
    ->update(['department_id' => $department]);
$team = DB::table('employees')->where('tenant_id', $tenantId)
    ->whereNotIn('id', $castIds)->where('department_id', $department)
    ->orderBy('id')->limit(7)->pluck('id')->push($employeeId)->all();
DB::table('employees')->where('tenant_id', $tenantId)->whereIn('id', $team)->update(['supervisor_id' => $supervisorId]);
$managerPosition = DB::table('positions')->where('tenant_id', $tenantId)->where('title', 'ሥራ አስኪያጅ')->value('id');
DB::table('employees')->where('id', $supervisorId)->update(['position_id' => $managerPosition]);

$preferences = json_encode(['locale' => 'am', 'calendar' => 'ethiopian']);
$accounts = [
    ['emp@demo.ethr.et', 'employee', $castIds[0]],
    [null, 'supervisor', $castIds[1]],
    ['hr@demo.ethr.et', 'hr_admin', $castIds[2]],
    ['admin@demo.ethr.et', 'tenant_admin', $castIds[3]],
];
foreach ($accounts as [$seededEmail, $role, $employee]) {
    $email = DB::table('employees')->where('id', $employee)->value('email');
    $values = [
        'display_name' => DB::table('employees')->where('id', $employee)->value('name_am'),
        'email' => $email,
        'email_normalized' => strtolower($email),
        'role' => $role,
        'employee_id' => $employee,
        'locale' => 'am',
        'preferences' => $preferences,
        'status' => 'active',
        'password' => Hash::make('password'),
        'mfa_enabled' => false,
        'updated_at' => now(),
    ];
    if ($seededEmail !== null && DB::table('users')->where('email', $seededEmail)->exists()) {
        DB::table('users')->where('email', $seededEmail)->update($values);
    } else {
        DB::table('users')->updateOrInsert(
            ['tenant_id' => $tenantId, 'email' => $email],
            $values + ['public_id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'created_at' => now(), 'activated_at' => now()],
        );
    }
    $userId = DB::table('users')->where('tenant_id', $tenantId)->where('email', $email)->value('id');
    DB::table('employees')->where('id', $employee)->update(['user_id' => $userId]);
}

echo "Dressed '{$database}': ".count($names)." people, accounts:\n";
foreach ($accounts as [, $role, $employee]) {
    echo '  '.str_pad($role, 13).DB::table('employees')->where('id', $employee)->value('email')." / password\n";
}
