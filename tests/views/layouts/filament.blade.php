<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Filament browser test</title>
    {{-- @filamentStyles/@filamentScripts are omitted: they need a registered
         Filament panel, which this bare fixture does not have, and the field
         wrapper view renders fine without the panel's own assets. --}}
    @livewireStyles
</head>
<body>
    {{ $slot ?? '' }}
    @livewireScripts
</body>
</html>
