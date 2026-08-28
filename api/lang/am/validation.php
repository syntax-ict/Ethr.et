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
];
