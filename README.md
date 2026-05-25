# ceedcv-maya/shared-profile-laravel

User profile endpoints for Laravel + Keycloak: GET /me, PUT /me/locale, configurable resolvers, FormRequest validation.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
composer require ceedcv-maya/shared-profile-laravel
```

Adds `GET /me` and `PUT /me/locale` endpoints to your Laravel app. Resolver is configurable to bind to your user model.

```php
// config/profile.php
return [
    'user_resolver' => \App\Resolvers\KeycloakUserResolver::class,
    'locale_writer' => \App\Resolvers\DbLocaleWriter::class,
];
```


## TypeScript / build notes
PSR-4 autoload from `src/`. Service providers are registered via Laravel package discovery (no manual provider registration needed).

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
