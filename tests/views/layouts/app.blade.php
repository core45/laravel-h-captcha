<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @livewireStyles
</head>
<body>
    {{ $slot ?? '' }}{{ $content ?? '' }}
    @livewireScripts
</body>
</html>
