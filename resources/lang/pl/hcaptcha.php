<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Weryfikacja captchy nie powiodła się. Spróbuj ponownie.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Uzupełnij captchę.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Usługa captchy jest niedostępna. Spróbuj ponownie za chwilę.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha wygasła. Uzupełnij ją ponownie.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Captcha nie została ukończona. Spróbuj ponownie.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Trwa weryfikacja, proszę czekać…',

];
