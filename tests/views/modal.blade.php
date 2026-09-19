<div>
    <button type="button" id="toggle" wire:click="toggle">Toggle</button>

    @if ($open)
        <div id="modal">
            <x-hcaptcha model="captcha" />
        </div>
    @endif

    <p id="state">{{ $open ? 'open' : 'closed' }}</p>
</div>
