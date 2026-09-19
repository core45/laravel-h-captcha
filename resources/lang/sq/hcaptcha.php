<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Verifikimi i captchës dështoi. Ju lutem, provoni përsëri.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Ju lutem, plotësoni captchën.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Shërbimi i captchës nuk është në dispozicion. Ju lutem, provoni përsëri në një çast.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha ka skaduar. Ju lutem, plotësojeni përsëri.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Captcha nuk u përfundua. Ju lutemi provoni përsëri.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Po verifikohet, ju lutemi prisni…',

];
