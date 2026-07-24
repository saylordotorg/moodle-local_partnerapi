<?php
// This file is part of the local_partnerapi Moodle plugin.
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Exhaustive and generated tests for affiliation provenance precedence.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

/**
 * Tests the pure precedence model without a third-party generator dependency.
 *
 * @covers \local_partnerapi\provenance
 */
final class provenance_property_test extends \basic_testcase {
    /** @var int Number of deterministic sequences exercised per property. */
    private const ITERATIONS = 128;

    /**
     * Return all representative source values.
     *
     * @return array<int, string|null> Valid, absent, and invalid sources.
     */
    private static function source_domain(): array {
        return [
            provenance::SOURCE_REGISTRATION,
            provenance::SOURCE_SIGNUP,
            provenance::SOURCE_SELF,
            null,
            '',
            'unknown',
            'bogus_source',
            'PARTNER_REGISTRATION_LINK',
            'self_affiliated ',
        ];
    }

    /**
     * Return the valid source constants.
     *
     * @return string[] Valid sources.
     */
    private static function valid_sources(): array {
        return [
            provenance::SOURCE_REGISTRATION,
            provenance::SOURCE_SIGNUP,
            provenance::SOURCE_SELF,
        ];
    }

    /**
     * Generate reproducible source sequences of varying lengths.
     *
     * @return array<int, array<int, string|null>> Generated sequences.
     */
    private static function generated_sequences(): array {
        $domain = self::source_domain();
        $sequences = [[]];

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $number = $iteration;
            $length = ($iteration % 7) + 1;
            $sequence = [];
            for ($position = 0; $position < $length; $position++) {
                $index = ($number + ($position * 3)) % count($domain);
                $sequence[] = $domain[$index];
                $number = intdiv($number, count($domain));
            }
            $sequences[] = $sequence;
        }

        return $sequences;
    }

    /**
     * Source ranks form a deterministic strict total order.
     *
     * @return void
     */
    public function test_rank_is_a_strict_total_order(): void {
        $domain = self::source_domain();

        foreach ($domain as $first) {
            foreach ($domain as $second) {
                foreach ($domain as $third) {
                    $firstrank = provenance::rank($first);
                    $secondrank = provenance::rank($second);
                    $thirdrank = provenance::rank($third);

                    $this->assertSame($firstrank, provenance::rank($first));
                    $this->assertGreaterThanOrEqual(0, $firstrank);
                    $this->assertLessThanOrEqual(3, $firstrank);
                    $comparisons = (int) ($firstrank < $secondrank)
                        + (int) ($firstrank > $secondrank)
                        + (int) ($firstrank === $secondrank);
                    $this->assertSame(1, $comparisons);

                    if ($firstrank >= $secondrank && $secondrank >= $thirdrank) {
                        $this->assertGreaterThanOrEqual($thirdrank, $firstrank);
                    }
                }
            }
        }

        $registration = provenance::rank(provenance::SOURCE_REGISTRATION);
        $signup = provenance::rank(provenance::SOURCE_SIGNUP);
        $self = provenance::rank(provenance::SOURCE_SELF);
        $absent = provenance::rank(null);
        $this->assertGreaterThan($signup, $registration);
        $this->assertGreaterThan($self, $signup);
        $this->assertGreaterThan($absent, $self);
        $this->assertSame(0, $absent);
        $this->assertCount(3, array_unique([$registration, $signup, $self]));
    }

    /**
     * Merging keeps the stronger source and never leaks invalid values.
     *
     * @return void
     */
    public function test_merge_source_keeps_higher_precedence(): void {
        $valid = self::valid_sources();
        foreach (self::source_domain() as $existing) {
            foreach (self::source_domain() as $incoming) {
                $merged = provenance::merge_source($existing, $incoming);
                $existingrank = provenance::rank($existing);
                $incomingrank = provenance::rank($incoming);

                $this->assertSame(max($existingrank, $incomingrank), provenance::rank($merged));
                $this->assertSame($merged, provenance::merge_source($existing, $incoming));
                if ($incomingrank <= $existingrank && $existingrank > 0) {
                    $this->assertSame($existing, $merged);
                }
                if ($merged === null) {
                    $this->assertSame(0, max($existingrank, $incomingrank));
                    continue;
                }
                $this->assertContains($merged, $valid);
            }
        }
    }

    /**
     * Apply source changes to a pure in-memory record model.
     *
     * @param array<string, string> $store Existing modeled store.
     * @param string $key Modeled user/cohort key.
     * @param array<int, string|null> $sources Sources to apply.
     * @return array<string, string> Updated modeled store.
     */
    private static function apply_sequence(array $store, string $key, array $sources): array {
        foreach ($sources as $incoming) {
            $merged = provenance::merge_source($store[$key] ?? null, $incoming);
            if ($merged !== null) {
                $store[$key] = $merged;
            }
        }
        return $store;
    }

    /**
     * Repeated recording is idempotent and independent of source order.
     *
     * @return void
     */
    public function test_recording_is_idempotent_per_user_cohort(): void {
        $key = '42:7';
        foreach (self::generated_sequences() as $sources) {
            $store = self::apply_sequence([], $key, $sources);
            $this->assertLessThanOrEqual(1, count($store));
            $this->assertSame($store, self::apply_sequence($store, $key, $sources));
            $this->assertSame($store, self::apply_sequence([], $key, array_reverse($sources)));

            $expectedrank = 0;
            foreach ($sources as $source) {
                $expectedrank = max($expectedrank, provenance::rank($source));
            }
            if ($expectedrank === 0) {
                $this->assertSame([], $store);
                continue;
            }
            $this->assertArrayHasKey($key, $store);
            $this->assertSame($expectedrank, provenance::rank($store[$key]));
        }
    }

    /**
     * Resolve a sequence to its single strongest valid source.
     *
     * @param array<int, string|null> $values Stored source values.
     * @return string|null Resolved value.
     */
    private static function resolve(array $values): ?string {
        $resolved = null;
        foreach ($values as $value) {
            $resolved = provenance::merge_source($resolved, $value);
        }
        return $resolved;
    }

    /**
     * Resolved API values are null or exactly one valid source.
     *
     * @return void
     */
    public function test_resolved_source_is_closed_over_valid_values(): void {
        $valid = self::valid_sources();
        foreach (self::generated_sequences() as $values) {
            $resolved = self::resolve($values);
            $maxrank = 0;
            foreach ($values as $value) {
                $maxrank = max($maxrank, provenance::rank($value));
            }

            $this->assertSame($resolved, self::resolve($values));
            $this->assertSame($maxrank, provenance::rank($resolved));
            if ($resolved === null) {
                $this->assertSame(0, $maxrank);
                continue;
            }
            $this->assertContains($resolved, $valid);
        }
    }
}
