<div>
    <form wire:submit="submit" id="form-{{ $label }}">
        <input type="text" wire:model="name" id="name-{{ $label }}">
        <x-hcaptcha model="captcha" :options="['size' => $size]" />
        <button type="submit" id="send-{{ $label }}">Send {{ $label }}</button>
    </form>

    @error('name') <p class="error-name">{{ $message }}</p> @enderror
    @error('captcha') <p class="error-captcha">{{ $message }}</p> @enderror

    <p id="submissions-{{ $label }}">Submitted {{ $submissions }} times</p>
    <p id="renders-{{ $label }}">Rendered {{ $renders }} times</p>
    <button type="button" wire:click="$refresh" id="refresh-{{ $label }}">Refresh</button>
</div>
