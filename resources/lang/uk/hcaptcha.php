<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Перевірка captcha не вдалась. Будь ласка, спробуйте ще раз.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Будь ласка, заповніть captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Служба captcha недоступна. Будь ласка, спробуйте ще раз за деякий час.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha закінчилась. Будь ласка, заповніть її ще раз.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
