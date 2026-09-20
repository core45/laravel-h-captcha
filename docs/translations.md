# Translations

The 22 shipped locales and the keys each locale file needs.

← back to the [README](../README.md)

22 locales ship in `resources/lang`: `bg`, `cs`, `de`, `el`, `en`, `es`, `et`, `fi`, `fr`, `hr`, `hu`, `it`, `lt`, `lv`, `nl`, `pl`, `pt`, `sk`, `sl`, `sq`, `sv`, `uk`.

Publish and customize with:

```bash
php artisan vendor:publish --tag=hcaptcha-lang
```

Each locale file (`hcaptcha::hcaptcha.*`) has five keys:

| Key | Used for |
| --- | --- |
| `failed` | Token rejected by hCaptcha |
| `missing` | Form submitted with no token |
| `unavailable` | hCaptcha unreachable, failed closed |
| `expired` | Token already spent |
| `label` | Accessible label on the widget wrapper / Filament field |
