<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Amharic Validation Overrides
|--------------------------------------------------------------------------
|
| Only the ETHR-specific lines are translated here. Framework validation
| messages fall through to lang/en/validation.php via the fallback locale.
|
*/

return [
    'file_content_mismatch' => 'የፋይሉ ይዘት ከተገለጸው ዓይነት ጋር አይዛመድም።',
    'file_magic_bytes_invalid' => 'የፋይሉ ይዘት ከተገለጸው ዓይነት ጋር ማረጋገጥ አልተቻለም።',
    'base64_image_invalid' => 'የምስሉ ዳታ ትክክለኛ base64 ምስል አይደለም።',
    'base64_image_unsupported_type' => 'JPEG፣ PNG እና WebP ምስሎች ብቻ ተቀባይነት አላቸው።',
    'base64_image_too_large' => 'ምስሉ ከ:max ኪባ መብለጥ የለበትም።',
    // English placeholder until the native-speaker review.
    'custom_domain_reserved' => 'A custom domain must be the organisation\'s own domain, not one under :root.',
    'custom_domain_not_in_plan' => 'This organisation\'s plan does not include a custom domain.',
    'custom_domain_txt_missing' => 'No TXT record :name with the value :value was found.',
    'custom_domain_cname_missing' => ':domain is not a CNAME for :target.',
    'custom_domain_none' => 'This organisation has no custom domain to verify.',
];
