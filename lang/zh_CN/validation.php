<?php

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

    'accepted' => ':attribute 必须被接受。',
    'accepted_if' => '当 :other 为 :value 时，:attribute 必须被接受。',
    'active_url' => ':attribute 必须是一个有效的 URL 地址。',
    'after' => ':attribute 必须是 :date 之后的日期。',
    'after_or_equal' => ':attribute 必须是 :date 之后或相同的日期。',
    'alpha' => ':attribute 只能包含字母。',
    'alpha_dash' => ':attribute 只能包含字母、数字、短横线及下划线。',
    'alpha_num' => ':attribute 只能包含字母和数字。',
    'any_of' => ':attribute 格式无效。',
    'array' => ':attribute 必须是一个数组。',
    'ascii' => ':attribute 只能包含单字节的字母、数字或符号。',
    'before' => ':attribute 必须是 :date 之前的日期。',
    'before_or_equal' => ':attribute 必须是 :date 之前或相同的日期。',
    'between' => [
        'array' => ':attribute 必须包含 :min 到 :max 个项目。',
        'file' => ':attribute 的大小必须在 :min 到 :max KB 之间。',
        'numeric' => ':attribute 的数值必须在 :min 到 :max 之间。',
        'string' => ':attribute 的长度必须在 :min 到 :max 个字符之间。',
    ],
    'boolean' => ':attribute 必须为 true 或 false。',
    'can' => ':attribute 包含未授权的值。',
    'confirmed' => ':attribute 的确认信息不匹配。',
    'contains' => ':attribute 缺少必要的值。',
    'current_password' => '密码错误。',
    'date' => ':attribute 必须是一个有效的日期。',
    'date_equals' => ':attribute 必须是一个等于 :date 的日期。',
    'date_format' => ':attribute 必须匹配格式 :format。',
    'decimal' => ':attribute 必须保留 :decimal 位小数。',
    'declined' => ':attribute 必须被拒绝。',
    'declined_if' => '当 :other 为 :value 时，:attribute 必须被拒绝。',
    'different' => ':attribute 和 :other 必须不同。',
    'digits' => ':attribute 必须是 :digits 位数字。',
    'digits_between' => ':attribute 必须在 :min 到 :max 位数字之间。',
    'dimensions' => ':attribute 图片尺寸无效。',
    'distinct' => ':attribute 字段包含重复值。',
    'doesnt_contain' => ':attribute 不能包含以下值：:values。',
    'doesnt_end_with' => ':attribute 不能以以下值结尾：:values。',
    'doesnt_start_with' => ':attribute 不能以以下值开头：:values。',
    'email' => ':attribute 必须是一个有效的电子邮件地址。',
    'encoding' => ':attribute 的编码必须为 :encoding。',
    'ends_with' => ':attribute 必须以以下值之一结尾：:values。',
    'enum' => '选定的 :attribute 无效。',
    'exists' => '选定的 :attribute 无效。',
    'extensions' => ':attribute 必须具有以下扩展名之一：:values。',
    'file' => ':attribute 必须是一个文件。',
    'filled' => ':attribute 字段必须有值。',
    'gt' => [
        'array' => ':attribute 必须包含超过 :value 个项目。',
        'file' => ':attribute 必须大于 :value KB。',
        'numeric' => ':attribute 必须大于 :value。',
        'string' => ':attribute 必须大于 :value 个字符。',
    ],
    'gte' => [
        'array' => ':attribute 必须包含 :value 个或更多项目。',
        'file' => ':attribute 必须大于或等于 :value KB。',
        'numeric' => ':attribute 必须大于或等于 :value。',
        'string' => ':attribute 必须大于或等于 :value 个字符。',
    ],
    'hex_color' => ':attribute 必须是一个有效的十六进制颜色值。',
    'image' => ':attribute 必须是一张图片。',
    'in' => '选定的 :attribute 无效。',
    'in_array' => ':attribute 字段必须存在于 :other 中。',
    'in_array_keys' => ':attribute 字段必须包含以下键之一：:values。',
    'integer' => ':attribute 必须是一个整数。',
    'ip' => ':attribute 必须是一个有效的 IP 地址。',
    'ipv4' => ':attribute 必须是一个有效的 IPv4 地址。',
    'ipv6' => ':attribute 必须是一个有效的 IPv6 地址。',
    'json' => ':attribute 必须是一个有效的 JSON 字符串。',
    'list' => ':attribute 必须是一个列表。',
    'lowercase' => ':attribute 必须为小写。',
    'lt' => [
        'array' => ':attribute 必须少于 :value 个项目。',
        'file' => ':attribute 必须小于 :value KB。',
        'numeric' => ':attribute 必须小于 :value。',
        'string' => ':attribute 必须少于 :value 个字符。',
    ],
    'lte' => [
        'array' => ':attribute 包含的项目不能超过 :value 个。',
        'file' => ':attribute 必须小于或等于 :value KB。',
        'numeric' => ':attribute 必须小于或等于 :value。',
        'string' => ':attribute 必须少于或等于 :value 个字符。',
    ],
    'mac_address' => ':attribute 必须是一个有效的 MAC 地址。',
    'max' => [
        'array' => ':attribute 最多只能包含 :max 个项目。',
        'file' => ':attribute 的大小不能超过 :max KB。',
        'numeric' => ':attribute 的数值不能超过 :max。',
        'string' => ':attribute 的长度不能超过 :max 个字符。',
    ],
    'max_digits' => ':attribute 的位数不能超过 :max 位。',
    'mimes' => ':attribute 必须是以下类型的文件：:values。',
    'mimetypes' => ':attribute 必须是以下类型的文件：:values。',
    'min' => [
        'array' => ':attribute 至少需要包含 :min 个项目。',
        'file' => ':attribute 的大小至少为 :min KB。',
        'numeric' => ':attribute 的数值至少为 :min。',
        'string' => ':attribute 的长度至少为 :min 个字符。',
    ],
    'min_digits' => ':attribute 的位数至少要有 :min 位。',
    'missing' => ':attribute 必须缺失。',
    'missing_if' => '当 :other 为 :value 时，:attribute 必须缺失。',
    'missing_unless' => '除非 :other 为 :value，否则 :attribute 必须缺失。',
    'missing_with' => '当 :values 存在时，:attribute 必须缺失。',
    'missing_with_all' => '当 :values 都存在时，:attribute 必须缺失。',
    'multiple_of' => ':attribute 必须是 :value 的倍数。',
    'not_in' => '选定的 :attribute 无效。',
    'not_regex' => ':attribute 的格式无效。',
    'numeric' => ':attribute 必须是一个数字。',
    'password' => [
        'letters' => ':attribute 必须包含至少一个字母。',
        'mixed' => ':attribute 必须包含至少一个大写字母和一个小写字母。',
        'numbers' => ':attribute 必须包含至少一个数字。',
        'symbols' => ':attribute 必须包含至少一个特殊符号。',
        'uncompromised' => '给定的 :attribute 已经出现在泄露的数据中，请更换一个 :attribute。',
    ],
    'present' => ':attribute 字段必须存在。',
    'present_if' => '当 :other 为 :value 时，:attribute 字段必须存在。',
    'present_unless' => '除非 :other 为 :value，否则 :attribute 字段必须存在。',
    'present_with' => '当 :values 存在时，:attribute 字段必须存在。',
    'present_with_all' => '当 :values 都存在时，:attribute 字段必须存在。',
    'prohibited' => ':attribute 字段被禁止。',
    'prohibited_if' => '当 :other 为 :value 时，:attribute 字段被禁止。',
    'prohibited_if_accepted' => '当 :other 被接受时，:attribute 字段被禁止。',
    'prohibited_if_declined' => '当 :other 被拒绝时，:attribute 字段被禁止。',
    'prohibited_unless' => '除非 :other 在 :values 中，否则 :attribute 字段被禁止。',
    'prohibits' => ':attribute 字段禁止 :other 同时存在。',
    'regex' => ':attribute 格式无效。',
    'required' => ':attribute 字段必填。',
    'required_array_keys' => ':attribute 必须包含以下键：:values。',
    'required_if' => '当 :other 为 :value 时，:attribute 字段必填。',
    'required_if_accepted' => '当 :other 被接受时，:attribute 字段必填。',
    'required_if_declined' => '当 :other 被拒绝时，:attribute 字段必填。',
    'required_unless' => '除非 :other 在 :values 中，否则 :attribute 字段必填。',
    'required_with' => '当 :values 存在时，:attribute 字段必填。',
    'required_with_all' => '当 :values 均存在时，:attribute 字段必填。',
    'required_without' => '当 :values 不存在时，:attribute 字段必填。',
    'required_without_all' => '当 :values 均不存在时，:attribute 字段必填。',
    'same' => ':attribute 必须与 :other 保持一致。',
    'size' => [
        'array' => ':attribute 必须包含 :size 个项目。',
        'file' => ':attribute 的大小必须为 :size KB。',
        'numeric' => ':attribute 的数值必须为 :size。',
        'string' => ':attribute 的长度必须为 :size 个字符。',
    ],
    'starts_with' => ':attribute 必须以以下值之一开头：:values。',
    'string' => ':attribute 必须是一个字符串。',
    'timezone' => ':attribute 必须是一个有效的时区。',
    'unique' => ':attribute 已经被占用了。',
    'uploaded' => ':attribute 上传失败。',
    'uppercase' => ':attribute 必须为大写。',
    'url' => ':attribute 必须是一个有效的 URL 地址。',
    'ulid' => ':attribute 必须是一个有效的 ULID。',
    'uuid' => ':attribute 必须是一个有效的 UUID。',

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
            'rule-name' => '自定义消息内容',
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

];
