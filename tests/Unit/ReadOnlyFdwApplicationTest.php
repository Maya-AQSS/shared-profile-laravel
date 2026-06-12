<?php

declare(strict_types=1);

use Maya\Profile\Models\ReadOnlyFdwApplication;

// ---------------------------------------------------------------------------
// Anonymous subclass fixtures — bring the abstract model to life.
//
// NOTE: Do NOT override __construct() — Eloquent's HasEvents trait internally
// calls `new static()` with zero arguments during boot, which would trigger an
// ArgumentCountError for any constructor that requires parameters.
//
// The correct approach is to use the $table property directly (as real
// application models do) or to instantiate with setTable() after construction.
// ---------------------------------------------------------------------------

/** Default table ('applications') subclass. */
function makeApplicationModel(): ReadOnlyFdwApplication
{
    return new class extends ReadOnlyFdwApplication {};
}

/** Custom-table subclass — overrides $table as a class property. */
function makeApplicationModelWithTable(string $table): ReadOnlyFdwApplication
{
    $model = new class extends ReadOnlyFdwApplication {};
    $model->setTable($table);

    return $model;
}

// ---------------------------------------------------------------------------
// UPDATED_AT = null (FDW views carry no updated_at column)
// ---------------------------------------------------------------------------

it('has UPDATED_AT constant set to null', function (): void {
    expect(ReadOnlyFdwApplication::UPDATED_AT)->toBeNull();
});

it('does not touch updated_at when saving (UPDATED_AT is null)', function (): void {
    $model = makeApplicationModel();

    // The model should not track the "updated at" column at all.
    expect($model->usesTimestamps())->toBeFalse();
})->skip('usesTimestamps() checks both CREATED_AT and UPDATED_AT; tested via UPDATED_AT constant instead');

// ---------------------------------------------------------------------------
// $guarded = ['*'] — model is read-only, mass-assignment is fully blocked
// ---------------------------------------------------------------------------

it('has guarded set to ["*"] (fully guarded)', function (): void {
    $model = makeApplicationModel();

    expect($model->getGuarded())->toBe(['*']);
});

it('is not unguarded', function (): void {
    $model = makeApplicationModel();

    expect($model->isUnguarded())->toBeFalse();
});

// ---------------------------------------------------------------------------
// created_at cast to datetime
// ---------------------------------------------------------------------------

it('casts created_at as datetime', function (): void {
    $model = makeApplicationModel();
    $casts  = $model->getCasts();

    expect($casts)->toHaveKey('created_at')
        ->and($casts['created_at'])->toBe('datetime');
});

// ---------------------------------------------------------------------------
// Default table is 'applications'
// ---------------------------------------------------------------------------

it('uses "applications" as the default table name', function (): void {
    // Instantiate through a subclass that does NOT override $table.
    $model = new class extends ReadOnlyFdwApplication {};

    expect($model->getTable())->toBe('applications');
});

// ---------------------------------------------------------------------------
// Table is configurable via subclass override
// ---------------------------------------------------------------------------

it('allows subclasses to override the table name', function (): void {
    $model = makeApplicationModelWithTable('fdw_applications');

    expect($model->getTable())->toBe('fdw_applications');
});

// ---------------------------------------------------------------------------
// No relations defined on the abstract model itself
// ---------------------------------------------------------------------------

it('defines no Eloquent relations on the base abstract model', function (): void {
    $model = makeApplicationModel();

    // Reflect the concrete anonymous class; base class should have no public
    // methods whose name starts with a lowercase letter and returns a Relation.
    $baseMethods = (new ReflectionClass(ReadOnlyFdwApplication::class))->getMethods(ReflectionMethod::IS_PUBLIC);

    $relationMethods = array_filter($baseMethods, function (ReflectionMethod $m): bool {
        // Skip Eloquent framework methods — only look at methods declared directly
        // on ReadOnlyFdwApplication, not inherited from Model.
        return $m->getDeclaringClass()->getName() === ReadOnlyFdwApplication::class
            && ! str_starts_with($m->getName(), '__');
    });

    expect($relationMethods)->toBeEmpty();
});
