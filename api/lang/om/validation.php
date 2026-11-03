<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attribute fudhatamuu qaba.',
    'accepted_if' => ':other :value yeroo ta\'u, :attribute fudhatamuu qaba.',
    'active_url' => ':attribute URL sirrii ta\'uu qaba.',
    'after' => ':attribute guyyaa :date booda ta\'uu qaba.',
    'after_or_equal' => ':attribute guyyaa :date ykn isa booda ta\'uu qaba.',
    'alpha' => ':attribute qubee qofa qabaachuu qaba.',
    'alpha_dash' => ':attribute qubee, lakkoofsa, sarara gabaabaa (-) fi sarara jalaa (_) qofa qabaachuu qaba.',
    'alpha_num' => ':attribute qubee fi lakkoofsa qofa qabaachuu qaba.',
    'any_of' => ':attribute sirrii miti.',
    'array' => ':attribute tarree (array) ta\'uu qaba.',
    'ascii' => ':attribute qubeewwan fi mallattoolee baayitii tokkoo qofa qabaachuu qaba.',
    'before' => ':attribute guyyaa :date dura ta\'uu qaba.',
    'before_or_equal' => ':attribute guyyaa :date ykn isa dura ta\'uu qaba.',
    'between' => [
        'array' => ':attribute wantoota :min fi :max gidduu qabaachuu qaba.',
        'file' => ':attribute kilobaayitii :min fi :max gidduu ta\'uu qaba.',
        'numeric' => ':attribute :min fi :max gidduu ta\'uu qaba.',
        'string' => ':attribute qubee :min fi :max gidduu ta\'uu qaba.',
    ],
    'boolean' => ':attribute dhugaa ykn soba ta\'uu qaba.',
    'can' => ':attribute gatii hin hayyamamne qaba.',
    'confirmed' => 'Mirkaneessi :attribute wal hin simu.',
    'contains' => ':attribute gatii barbaachisaa tokko dhabeera.',
    'current_password' => 'Jechi icciitii sirrii miti.',
    'date' => ':attribute guyyaa sirrii ta\'uu qaba.',
    'date_equals' => ':attribute guyyaa :date waliin walqixa ta\'uu qaba.',
    'date_format' => ':attribute akkaataa :format waliin wal simuu qaba.',
    'decimal' => ':attribute bakka desimaalaa :decimal qabaachuu qaba.',
    'declined' => ':attribute didamuu qaba.',
    'declined_if' => ':other :value yeroo ta\'u, :attribute didamuu qaba.',
    'different' => ':attribute fi :other adda adda ta\'uu qabu.',
    'digits' => ':attribute dijiitii :digits ta\'uu qaba.',
    'digits_between' => ':attribute dijiitii :min fi :max gidduu ta\'uu qaba.',
    'dimensions' => ':attribute safartuu suuraa sirrii hin taane qaba.',
    'distinct' => ':attribute gatii irra deddeebi\'e qaba.',
    'doesnt_contain' => ':attribute kanneen armaan gadii keessaa tokkollee qabaachuu hin qabu: :values.',
    'doesnt_end_with' => ':attribute kanneen armaan gadii keessaa tokkoon xumuramuu hin qabu: :values.',
    'doesnt_start_with' => ':attribute kanneen armaan gadii keessaa tokkoon jalqabuu hin qabu: :values.',
    'email' => ':attribute teessoo imeelii sirrii ta\'uu qaba.',
    'encoding' => ':attribute akkaataa koodii :encoding qabaachuu qaba.',
    'ends_with' => ':attribute kanneen armaan gadii keessaa tokkoon xumuramuu qaba: :values.',
    'enum' => ':attribute filatame sirrii miti.',
    'exists' => ':attribute filatame sirrii miti.',
    'extensions' => ':attribute dheerina faayilii (extension) armaan gadii keessaa tokko qabaachuu qaba: :values.',
    'file' => ':attribute faayilii ta\'uu qaba.',
    'filled' => ':attribute gatii qabaachuu qaba.',
    'gt' => [
        'array' => ':attribute wantoota :value caalaa qabaachuu qaba.',
        'file' => ':attribute kilobaayitii :value caaluu qaba.',
        'numeric' => ':attribute :value caaluu qaba.',
        'string' => ':attribute qubee :value caaluu qaba.',
    ],
    'gte' => [
        'array' => ':attribute wantoota :value ykn isaa ol qabaachuu qaba.',
        'file' => ':attribute kilobaayitii :value ykn isaa ol ta\'uu qaba.',
        'numeric' => ':attribute :value ykn isaa ol ta\'uu qaba.',
        'string' => ':attribute qubee :value ykn isaa ol ta\'uu qaba.',
    ],
    'hex_color' => ':attribute halluu heksaadesimaalaa sirrii ta\'uu qaba.',
    'image' => ':attribute suuraa ta\'uu qaba.',
    'in' => ':attribute filatame sirrii miti.',
    'in_array' => ':attribute :other keessa jiraachuu qaba.',
    'in_array_keys' => ':attribute yoo xiqqaate furtuuwwan armaan gadii keessaa tokko qabaachuu qaba: :values.',
    'integer' => ':attribute lakkoofsa guutuu ta\'uu qaba.',
    'ip' => ':attribute teessoo IP sirrii ta\'uu qaba.',
    'ipv4' => ':attribute teessoo IPv4 sirrii ta\'uu qaba.',
    'ipv6' => ':attribute teessoo IPv6 sirrii ta\'uu qaba.',
    'json' => ':attribute barreeffama JSON sirrii ta\'uu qaba.',
    'list' => ':attribute tarreeffama ta\'uu qaba.',
    'lowercase' => ':attribute qubee xiqqaa ta\'uu qaba.',
    'lt' => [
        'array' => ':attribute wantoota :value gadi qabaachuu qaba.',
        'file' => ':attribute kilobaayitii :value gadi ta\'uu qaba.',
        'numeric' => ':attribute :value gadi ta\'uu qaba.',
        'string' => ':attribute qubee :value gadi ta\'uu qaba.',
    ],
    'lte' => [
        'array' => ':attribute wantoota :value caalaa qabaachuu hin qabu.',
        'file' => ':attribute kilobaayitii :value ykn isaa gadi ta\'uu qaba.',
        'numeric' => ':attribute :value ykn isaa gadi ta\'uu qaba.',
        'string' => ':attribute qubee :value ykn isaa gadi ta\'uu qaba.',
    ],
    'mac_address' => ':attribute teessoo MAC sirrii ta\'uu qaba.',
    'max' => [
        'array' => ':attribute wantoota :max caalaa qabaachuu hin qabu.',
        'file' => ':attribute kilobaayitii :max caaluu hin qabu.',
        'numeric' => ':attribute :max caaluu hin qabu.',
        'string' => ':attribute qubee :max caaluu hin qabu.',
    ],
    'max_digits' => ':attribute dijiitii :max caalaa qabaachuu hin qabu.',
    'mimes' => ':attribute faayilii gosa kanneen keessaa tokko ta\'uu qaba: :values.',
    'mimetypes' => ':attribute faayilii gosa kanneen keessaa tokko ta\'uu qaba: :values.',
    'min' => [
        'array' => ':attribute yoo xiqqaate wantoota :min qabaachuu qaba.',
        'file' => ':attribute yoo xiqqaate kilobaayitii :min ta\'uu qaba.',
        'numeric' => ':attribute yoo xiqqaate :min ta\'uu qaba.',
        'string' => ':attribute yoo xiqqaate qubee :min ta\'uu qaba.',
    ],
    'min_digits' => ':attribute yoo xiqqaate dijiitii :min qabaachuu qaba.',
    'missing' => ':attribute jiraachuu hin qabu.',
    'missing_if' => ':other :value yeroo ta\'u, :attribute jiraachuu hin qabu.',
    'missing_unless' => ':other :value yoo ta\'e malee, :attribute jiraachuu hin qabu.',
    'missing_with' => ':values yeroo jiru, :attribute jiraachuu hin qabu.',
    'missing_with_all' => ':values yeroo jiran, :attribute jiraachuu hin qabu.',
    'multiple_of' => ':attribute dachaa :value ta\'uu qaba.',
    'not_in' => ':attribute filatame sirrii miti.',
    'not_regex' => 'Akkaataan :attribute sirrii miti.',
    'numeric' => ':attribute lakkoofsa ta\'uu qaba.',
    'password' => [
        'letters' => ':attribute yoo xiqqaate qubee tokko qabaachuu qaba.',
        'mixed' => ':attribute yoo xiqqaate qubee guddaa tokko fi qubee xiqqaa tokko qabaachuu qaba.',
        'numbers' => ':attribute yoo xiqqaate lakkoofsa tokko qabaachuu qaba.',
        'symbols' => ':attribute yoo xiqqaate mallattoo tokko qabaachuu qaba.',
        'uncompromised' => ':attribute kenname daataa saamame (data leak) keessatti mul\'ateera. Maaloo :attribute biraa filadhaa.',
    ],
    'present' => ':attribute jiraachuu qaba.',
    'present_if' => ':other :value yeroo ta\'u, :attribute jiraachuu qaba.',
    'present_unless' => ':other :value yoo ta\'e malee, :attribute jiraachuu qaba.',
    'present_with' => ':values yeroo jiru, :attribute jiraachuu qaba.',
    'present_with_all' => ':values yeroo jiran, :attribute jiraachuu qaba.',
    'prohibited' => ':attribute dhorkameera.',
    'prohibited_if' => ':other :value yeroo ta\'u, :attribute dhorkameera.',
    'prohibited_if_accepted' => ':other yeroo fudhatamu, :attribute dhorkameera.',
    'prohibited_if_declined' => ':other yeroo didamu, :attribute dhorkameera.',
    'prohibited_unless' => ':other :values keessa yoo jiraate malee, :attribute dhorkameera.',
    'prohibits' => ':attribute akka :other jiraatu hin hayyamu.',
    'regex' => 'Akkaataan :attribute sirrii miti.',
    'required' => ':attribute barbaachisaadha.',
    'required_array_keys' => ':attribute galmee kanneeniif qabaachuu qaba: :values.',
    'required_if' => ':other :value yeroo ta\'u, :attribute barbaachisaadha.',
    'required_if_accepted' => ':other yeroo fudhatamu, :attribute barbaachisaadha.',
    'required_if_declined' => ':other yeroo didamu, :attribute barbaachisaadha.',
    'required_unless' => ':other :values keessa yoo jiraate malee, :attribute barbaachisaadha.',
    'required_with' => ':values yeroo jiru, :attribute barbaachisaadha.',
    'required_with_all' => ':values yeroo jiran, :attribute barbaachisaadha.',
    'required_without' => ':values yeroo hin jirre, :attribute barbaachisaadha.',
    'required_without_all' => ':values keessaa tokkollee yeroo hin jirre, :attribute barbaachisaadha.',
    'same' => ':attribute :other waliin wal simuu qaba.',
    'size' => [
        'array' => ':attribute wantoota :size qabaachuu qaba.',
        'file' => ':attribute kilobaayitii :size ta\'uu qaba.',
        'numeric' => ':attribute :size ta\'uu qaba.',
        'string' => ':attribute qubee :size ta\'uu qaba.',
    ],
    'starts_with' => ':attribute kanneen armaan gadii keessaa tokkoon jalqabuu qaba: :values.',
    'string' => ':attribute barreeffama (string) ta\'uu qaba.',
    'timezone' => ':attribute godina yeroo sirrii ta\'uu qaba.',
    'unique' => ':attribute duraan fudhatameera.',
    'post_too_large' => 'Olfe\'iinsi kun kan sarvarri fudhatu caala. Faayiliin guddaan inni fudhatu :max dha.',
    'uploaded' => ':attribute olfe\'amuu hin dandeenye. Kan sarvarri fudhatu caaluu danda\'a.',
    'uppercase' => ':attribute qubee guddaa ta\'uu qaba.',
    'url' => ':attribute URL sirrii ta\'uu qaba.',
    'ulid' => ':attribute ULID sirrii ta\'uu qaba.',
    'uuid' => ':attribute UUID sirrii ta\'uu qaba.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

    /*
    |--------------------------------------------------------------------------
    | ETHR File & Image Validation Lines
    |--------------------------------------------------------------------------
    |
    | Used by App\Rules\VerifyFileContent and App\Rules\Base64Image, which
    | enforce convention #15 (uploaded content must match its declared type).
    |
    */

    'file_content_mismatch' => 'Qabiyyeen faayilii gosa ibsame waliin wal hin simu.',
    'file_magic_bytes_invalid' => 'Qabiyyeen faayilii gosa ibsame waliin mirkanaa\'uu hin dandeenye.',
    'base64_image_invalid' => 'Daataan suuraa suuraa base64 sirrii miti.',
    'base64_image_unsupported_type' => 'Suuraalee JPEG, PNG fi WebP qofatu fudhatama.',
    'base64_image_too_large' => 'Suuraan KB :max caaluu hin qabu.',
    'cover_too_narrow' => 'Suuraan haguuggii yoo xiqqaate bal\'ina piikseelii :width qabaachuu qaba.',
    'landing_cover_missing' => 'Duubbee suuraa filachuu dura suuraa haguuggii olfe\'aa.',
    'landing_link_host' => 'Liinkiin kun :hosts irratti ta\'uu qaba.',
    'landing_faq_question' => 'Gaaffii sana Afaan Ingiliffaa, Afaan Amaaraa ykn Afaan Oromootiin barreessaa.',
    'landing_faq_answer' => 'Deebii sana Afaan Ingiliffaa, Afaan Amaaraa ykn Afaan Oromootiin barreessaa.',
    'landing_service_title' => 'Tajaajilichaaf Afaan Ingiliffaa, Afaan Amaaraa ykn Afaan Oromootiin maqaa kennaa.',
    'announcement_files_max' => 'Oduun tokko yoo baay\'ate sanadoota :max qabaachuu danda\'a.',
    // AppRulesPermittedMarketingClaim: the public site may not make this claim.
    'marketing_claim' => 'Barreeffamni kun ibsa marsariitiin ummataa gochuu hin dandeenye qaba. Tarreeffama isaatiif qajeelfama qabiyyee gabaa ilaalaa.',
    'marketing_field_unused' => 'Kutaan :kind dirree kana hin fayyadamu.',
    'marketing_items_max' => 'Kutaan :kind yoo baay\'ate toora :max qabata.',

    // App\Http\Requests\Admin\UpdateTenantDomainRequest: a host ETHR already owns.
    'custom_domain_reserved' => 'Domeeniin dhuunfaa domeenii mataa dhaabbatichaa ta\'uu qaba, kan :root jala jiru miti.',
    // UpdateTenantDomainRequest again: the plan gate.
    'custom_domain_not_in_plan' => 'Karoorri dhaabbata kanaa domeenii dhuunfaa hin qabatu.',
    // AdminTenantController::verifyDomain: each DNS check that did not pass.
    'custom_domain_txt_missing' => 'Galmeen TXT :name gatii :value qabu hin argamne.',
    'custom_domain_cname_missing' => ':domain CNAME gara :target miti.',
    'custom_domain_none' => 'Dhaabbanni kun domeenii dhuunfaa mirkanaa\'u hin qabu.',
    // UpdateSubdomainRequest and TenantAddressService::changeSubdomain: renaming the address.
    'subdomain_format' => 'Qubee xiqqaa, lakkoofsa fi sarara gabaabaa (-) fayyadamaa; maqaan qubee ykn lakkoofsaan jalqabee xumuramuu qaba.',
    'subdomain_reserved' => 'Maqaan sun ETHR\'n qabameera. Maaloo kan biraa filadhaa.',
    'subdomain_taken' => 'Maqaan sun duraan itti fayyadamamaa jira. Maaloo kan biraa filadhaa.',

    'employee_minimum_age' => 'Hojjetaan guyyaa qacarrii irratti yoo xiqqaate umurii waggaa 15 qabaachuu qaba.',
    'holiday_duplicate' => 'Ayyaanni kun guyyaa sanaaf duraan dhaha irra jira.',
    'leave_paid_needs_days' => 'Gosni boqonnaa kaffaltii qabu yoo xiqqaate mirga walakkaa guyyaa barbaada. 0 boqonnaa kaffaltii malee qofaaf fayyadamaa.',
];
