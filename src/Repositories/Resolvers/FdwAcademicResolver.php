<?php

namespace Maya\Profile\Repositories\Resolvers;

use Maya\Profile\Dtos\UserProfileDto;
use Maya\Profile\Repositories\Contracts\AcademicDataReaderInterface;
use Maya\Profile\Repositories\Contracts\UserProfileResolverInterface;
use Maya\Profile\Repositories\Readers\AcademicDataReader;

/**
 * Resolver con enriquecimiento académico completo.
 *
 * Combina:
 *  - `FdwEnrichedJwtResolver` → permisos vía FDW `user_resolved_permissions`.
 *  - `AcademicDataReader`     → campos `study_type_ids`, `study_ids`,
 *                               `module_ids`, `team_ids`, `teams[]` desde FDW
 *                               académicas locales.
 *
 * Política cross-app: TODAS las apps Maya proyectan estas vistas FDW
 * localmente (mismo patrón que `maya_dms`) para que `/me` sea transversal
 * y resiliente — si una app cae, el resto sigue autenticando sin
 * dependencias cruzadas.
 *
 * Para apps que NO leen permisos vía FDW (ej. `maya_authorization`, que ES
 * la fuente de verdad de permisos), implementar un resolver propio que use
 * directamente {@see AcademicDataReader} y combine con su fuente local de
 * permisos.
 */
final class FdwAcademicResolver implements UserProfileResolverInterface
{
    public function __construct(
        private readonly FdwEnrichedJwtResolver $base = new FdwEnrichedJwtResolver(),
        private readonly AcademicDataReaderInterface $academic = new AcademicDataReader(),
    ) {}

    public function resolve(string $userId, array $jwtProfile): UserProfileDto
    {
        $dto = $this->base->resolve($userId, $jwtProfile);

        // Contrato cross-app: `permissions` SIEMPRE presente (aunque sea []).
        // El base resolver puede omitirla si la FDW de permisos no está
        // disponible — la blindamos aquí para mantener el shape uniforme.
        $extra = $dto->extra;
        if (! array_key_exists('permissions', $extra)) {
            $extra['permissions'] = [];
        }

        return new UserProfileDto(
            id:     $dto->id,
            email:  $dto->email,
            name:   $dto->name,
            locale: $dto->locale,
            extra:  array_merge($extra, $this->academic->read($dto->id)),
        );
    }

    public function invalidate(string $userId): void
    {
        $this->base->invalidate($userId);
        $this->academic->invalidate($userId);
    }
}
