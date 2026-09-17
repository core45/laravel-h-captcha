<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'A verificação do captcha falhou. Tente novamente.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Por favor, complete o captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'O serviço de captcha está indisponível. Por favor, tente novamente em um momento.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'O captcha expirou. Por favor, complete-o novamente.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
