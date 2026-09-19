<x-hcaptcha-tests::layouts.plain>
    <form method="post" action="{{ $action }}" id="widget-form">
        <input type="text" name="name" id="name">
        <x-hcaptcha :options="$options ?? []" />
        <button type="submit" id="send">Send</button>
    </form>
</x-hcaptcha-tests::layouts.plain>
