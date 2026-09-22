<?php
declare(strict_types=1);
namespace Dn\Policy;

/**
 * Measured RouterOS limits, keyed by model and firmware version.
 *
 * THIS BOOK IS EMPTY, AND THAT IS THE POINT.
 *
 * Audit findings R2 and R3 asked for these limits to be parameterised. The
 * temptation when parameterising an unverified number is to move it into a
 * config file, where it reads as configuration — something someone decided —
 * rather than as the guess it actually is. That would have converted an
 * unverified assumption into an apparently authoritative fact while changing
 * nothing about what we know, which is nothing.
 *
 * So the book ships with no entries. Every lookup falls through to
 * RouterOsLimits::unverified(), which announces itself as provisional, and a
 * test asserts that no entry here claims `verified: true` without a matching
 * line in docs/57 recording the bisection that produced it. When a physical
 * unit is finally measured, one entry lands here and that model stops using
 * the guard rail — model by model, as evidence arrives, rather than in one
 * hopeful sweep.
 *
 * Keying is model-then-version because both matter: a limit read off a hAP ax2
 * on 7.14 says nothing about an RB5009, and a firmware change can move it on
 * the same hardware.
 */
final class RouterOsLimitBook
{
    /**
     * @var array<string,array<string,RouterOsLimits>> model => version => limits
     */
    private array $book;

    /** @param array<string,array<string,RouterOsLimits>> $book */
    public function __construct(array $book = [])
    {
        $this->book = $book;
    }

    /**
     * The limits to apply to a plan.
     *
     * A plan is not bound to one device — a customer may run several — so when
     * no model is named, or none is measured, the provisional bound applies.
     * Narrowing a plan to the most restrictive limit across a customer's actual
     * fleet is a real question, and it is deliberately not answered here: there
     * is no measured limit to narrow to, so any binding written today would be
     * tested against a guess and would encode the guess in its shape.
     */
    public function for(?string $model = null, ?string $rosVersion = null): RouterOsLimits
    {
        if ($model === null || $rosVersion === null) {
            return RouterOsLimits::unverified();
        }
        return $this->book[$model][$rosVersion] ?? RouterOsLimits::unverified();
    }

    /** @return list<array{model:string,version:string,limits:RouterOsLimits}> */
    public function entries(): array
    {
        $out = [];
        foreach ($this->book as $model => $versions) {
            foreach ($versions as $version => $limits) {
                $out[] = ['model' => $model, 'version' => $version, 'limits' => $limits];
            }
        }
        return $out;
    }
}
