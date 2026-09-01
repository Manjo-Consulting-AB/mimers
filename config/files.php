<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uppladdningstak
    |--------------------------------------------------------------------------
    |
    | Tekniskt tak på filstorleken, inte en plangräns — samma tal som
    | upload_max_filesize i public/.htaccess, se [[Pipeline]] §
    | Uppladdningsgränser och issue 16a § Beslut 9. Ett tak som ligger över
    | PHP:s eget vore en gräns som aldrig slår i, och ett under en gräns som
    | går att förklara. Plangränserna (max_file_bytes, storage_bytes) är
    | issue 27 och sätts ovanpå den här kontrollen, inte i stället för den.
    |
    */

    'max_upload_bytes' => (int) env('FILES_MAX_UPLOAD_BYTES', 64 * 1024 * 1024),

];
