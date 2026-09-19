<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Captcha verification failed. Please try again.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Please complete the captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'The captcha service is unavailable. Please try again in a moment.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'The captcha expired. Please complete it again.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'The captcha did not complete. Please try again.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Verifying, please wait…',

];
