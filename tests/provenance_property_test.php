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
 * Property-based tests for the pure precedence logic in local_partnerapi\provenance.
 *
 * These tests use Eris (the QuickCheck-style property-based testing library for
 * PHPUnit). The logic under test is pure (no DB), so the test extends
 * \basic_testcase to avoid the database-reset overhead of advanced_testcase.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

use Eris\Generator;
use Eris\TestTrait;

/**
 * Property tests for provenance::rank (and, later, merge_source).
 *
 * @covers \local_partnerapi\provenance
 */
final class provenance_property_test extends \basic_testcase {

    use TestTrait;

    /** @var int Minimum number of generated examples per property (design requires >= 100). */
    const ITERATIONS = 100;

    /**
     * The set of values a generated "source" is drawn from: the three valid
     * sources plus null and a handful of invalid strings. The invalid values
     * exercise the "absent/invalid" rank-0 case required by Property 1.
     *
     * @return array<int, string|null> Candidate source values for generators.
     */
    private static function source_domain(): array {
        return [
            // The three determinable sources (ranks 3, 2, 1).
            provenance::SOURCE_REGISTRATION,
            provenance::SOURCE_SIGNUP,
            provenance::SOURCE_SELF,
            // Absent / invalid values (all must rank 0).
            null,
            '',
            'unknown',
            'bogus_source',
            'PARTNER_REGISTRATION_LINK', // Wrong case: not a valid source.
            'self_affiliated ',          // Trailing space: not a valid source.
        ];
    }

    /**
     * Feature: affiliation-source-provenance, Property 1: Source_Precedence is a strict total order
     *
     * For any two source values drawn from the three valid sources plus the
     * absent/invalid case, rank() assigns comparable integers such that exactly
     * one of rank(a) < rank(b), rank(a) > rank(b), or rank(a) == rank(b) holds
     * (trichotomy), the relation is deterministic and transitive, the fixed
     * ordering partner_registration_link > signup_partner_choice >
     * self_affiliated > absent holds, and the three valid sources have distinct
     * ranks.
     *
     * Validates: Requirements 1.6
     */
    public function test_rank_is_a_strict_total_order(): void {
        $domain = self::source_domain();

        $this->limitTo(self::ITERATIONS)
            ->forAll(
                Generator\elements($domain),
                Generator\elements($domain),
                Generator\elements($domain)
            )
            ->then(function ($a, $b, $c): void {
                $ra = provenance::rank($a);
                $rb = provenance::rank($b);
                $rc = provenance::rank($c);

                // Determinism: rank is a pure function of its input.
                $this->assertSame($ra, provenance::rank($a),
                    'rank() must be deterministic for the same input');

                // Ranks are always within the known closed range [0, 3].
                $this->assertGreaterThanOrEqual(0, $ra, 'rank() must never be negative');
                $this->assertLessThanOrEqual(3, $ra, 'rank() must never exceed 3');

                // Trichotomy / comparability: exactly one of <, >, == holds for any pair.
                $lt = $ra < $rb;
                $gt = $ra > $rb;
                $eq = $ra === $rb;
                $this->assertSame(1, (int)$lt + (int)$gt + (int)$eq,
                    'Exactly one of rank(a) < rank(b), rank(a) > rank(b), rank(a) == rank(b) must hold');

                // Transitivity of the ordering: a >= b and b >= c implies a >= c.
                if ($ra >= $rb && $rb >= $rc) {
                    $this->assertGreaterThanOrEqual($rc, $ra,
                        'Ordering must be transitive');
                }
            });
    }

    /**
     * Feature: affiliation-source-provenance, Property 1: Source_Precedence is a strict total order
     *
     * The fixed, distinct ordering of the determinable sources above the
     * absent/invalid case. Asserted directly (not generated) because it pins
     * the exact total order the property quantifies over.
     *
     * Validates: Requirements 1.6
     */
    public function test_rank_fixed_ordering_and_distinctness(): void {
        $registration = provenance::rank(provenance::SOURCE_REGISTRATION);
        $signup = provenance::rank(provenance::SOURCE_SIGNUP);
        $self = provenance::rank(provenance::SOURCE_SELF);
        $absent = provenance::rank(null);

        // Fixed ordering: REGISTRATION > SIGNUP > SELF > absent (== 0).
        $this->assertGreaterThan($signup, $registration);
        $this->assertGreaterThan($self, $signup);
        $this->assertGreaterThan($absent, $self);
        $this->assertSame(0, $absent);

        // Distinctness of the three valid sources' ranks.
        $ranks = [$registration, $signup, $self];
        $this->assertCount(3, array_unique($ranks),
            'The three valid sources must have distinct ranks');
    }

    /**
     * The three valid source constants. A merge result, when not null, must be
     * exactly one of these (an invalid/unknown value must never leak out).
     *
     * @return array<int, string> The three determinable source constants.
     */
    private static function valid_sources(): array {
        return [
            provenance::SOURCE_REGISTRATION,
            provenance::SOURCE_SIGNUP,
            provenance::SOURCE_SELF,
        ];
    }

    /**
     * Feature: affiliation-source-provenance, Property 2: Merge keeps the higher-precedence source and never downgrades
     *
     * For any existing source (including null/invalid) and any incoming source,
     * merge_source(existing, incoming) returns the value with the higher rank and
     * never lowers the stored rank. Concretely the merged rank equals
     * max(rank(existing), rank(incoming)); a lower-or-equal-rank incoming never
     * replaces a valid existing value; and the result is always one of the three
     * valid constants or null (an invalid value never leaks).
     *
     * Validates: Requirements 1.6, 3.4
     */
    public function test_merge_source_keeps_higher_precedence_and_never_downgrades(): void {
        $domain = self::source_domain();
        $valid = self::valid_sources();

        $this->limitTo(self::ITERATIONS)
            ->forAll(
                Generator\elements($domain),
                Generator\elements($domain)
            )
            ->then(function ($existing, $incoming) use ($valid): void {
                $merged = provenance::merge_source($existing, $incoming);

                $existingrank = provenance::rank($existing);
                $incomingrank = provenance::rank($incoming);
                $mergedrank = provenance::rank($merged);

                // Determinism: merge_source is a pure function of its inputs.
                $this->assertSame($merged, provenance::merge_source($existing, $incoming),
                    'merge_source() must be deterministic for the same inputs');

                // Never downgrade: the merged rank is at least the existing rank.
                $this->assertGreaterThanOrEqual($existingrank, $mergedrank,
                    'merge_source() must never lower the existing rank');

                // Merged rank equals the higher of the two input ranks.
                $this->assertSame(max($existingrank, $incomingrank), $mergedrank,
                    'merge_source() must keep the higher-precedence rank');

                // A lower-or-equal-rank incoming never replaces a valid existing value.
                if ($incomingrank <= $existingrank && $existingrank > 0) {
                    $this->assertSame($existing, $merged,
                        'A weaker-or-equal incoming must not replace a valid existing source');
                }

                // The result is always one of the three valid constants or null;
                // an invalid/unknown value never leaks through the merge.
                if ($merged !== null) {
                    $this->assertContains($merged, $valid,
                        'merge_source() must return null or one of the valid source constants');
                }

                // When both inputs rank 0 (null/invalid), the result is null.
                if ($existingrank === 0 && $incomingrank === 0) {
                    $this->assertNull($merged,
                        'Merging two absent/invalid values must yield null');
                }
            });
    }

    /**
     * Feature: affiliation-source-provenance, Property 2: Merge keeps the higher-precedence source and never downgrades
     *
     * Value-level idempotence: merging a source with itself never changes its
     * rank. For a valid source the result is that same source; for an
     * absent/invalid value the result normalizes to null (rank 0 either way).
     *
     * Validates: Requirements 1.6, 3.4
     */
    public function test_merge_source_is_idempotent_at_value_level(): void {
        $domain = self::source_domain();

        $this->limitTo(self::ITERATIONS)
            ->forAll(
                Generator\elements($domain)
            )
            ->then(function ($x): void {
                $merged = provenance::merge_source($x, $x);

                // Idempotent on rank: merging a value with itself preserves its rank.
                $this->assertSame(provenance::rank($x), provenance::rank($merged),
                    'merge_source(x, x) must preserve the rank of x');

                // A valid source merges to itself unchanged.
                if (provenance::rank($x) > 0) {
                    $this->assertSame($x, $merged,
                        'merge_source(x, x) must return x for a valid source');
                } else {
                    // Absent/invalid values normalize to null.
                    $this->assertNull($merged,
                        'merge_source(x, x) must return null for an absent/invalid value');
                }
            });
    }

    /**
     * Model of the I/O recorder, kept pure for property testing.
     *
     * Mirrors what provenance::record() does to its UNIQUE(userid, cohortid)
     * table, but as an in-memory associative array keyed by "userid:cohortid".
     * Applying an incoming source folds it into any existing value via
     * provenance::merge_source(existingOrNull, incoming); only a non-null merge
     * result is stored, so an absent/invalid source never creates an entry and
     * never downgrades a stored value. This is exactly the never-downgrade,
     * at-most-one-row behavior record() guarantees, with no Moodle DB required.
     *
     * @param array<string, string> $store Modeled store; "userid:cohortid" => source.
     * @param string $key The single (userid, cohortid) key being recorded against.
     * @param array<int, string|null> $sources Ordered sequence of recorded sources.
     * @return array<string, string> The store after applying every source in order.
     */
    private static function apply_sequence(array $store, string $key, array $sources): array {
        foreach ($sources as $incoming) {
            $existing = $store[$key] ?? null;
            $merged = provenance::merge_source($existing, $incoming);
            // Only a non-null (valid) merge result is ever stored; a null result
            // leaves the store untouched (no entry created, no entry downgraded).
            if ($merged !== null) {
                $store[$key] = $merged;
            }
        }
        return $store;
    }

    /**
     * Feature: affiliation-source-provenance, Property 3: Recording is idempotent per (userid, cohortid)
     *
     * For any (userid, cohortid) pair and any sequence of recorded sources, the
     * modeled store holds at most one entry for that pair: exactly one when any
     * valid source was applied, and none when every applied source was
     * absent/invalid. The stored value's rank equals the maximum rank over the
     * applied valid sources (never downgraded), and because the three valid
     * ranks are distinct that value is uniquely determined by the strongest
     * source seen — independent of order. Re-applying the already-stored value
     * (merge_source(x, x) == x) and re-applying the entire sequence a second
     * time both leave the store unchanged, as do deterministic reorderings of
     * the sequence.
     *
     * Validates: Requirements 1.5, 3.1
     */
    public function test_recording_is_idempotent_per_user_cohort(): void {
        $domain = self::source_domain();

        $this->limitTo(self::ITERATIONS)
            ->forAll(
                Generator\seq(Generator\elements($domain))
            )
            ->then(function (array $sources): void {
                // A single (userid, cohortid) pair all recordings target.
                $key = '42:7';

                // Apply the whole sequence to an initially empty store.
                $store = self::apply_sequence([], $key, $sources);

                // Independently compute the expected winner: the highest-rank
                // valid source in the sequence. Distinct ranks => a unique value.
                $expectedrank = 0;
                $expectedvalue = null;
                foreach ($sources as $s) {
                    $r = provenance::rank($s);
                    if ($r > $expectedrank) {
                        $expectedrank = $r;
                        $expectedvalue = $s;
                    }
                }

                // At most one entry for the pair, regardless of sequence length.
                $this->assertLessThanOrEqual(1, count($store),
                    'The store must never hold more than one entry per (userid, cohortid)');

                if ($expectedrank === 0) {
                    // Every applied source was absent/invalid: no entry exists.
                    $this->assertCount(0, $store,
                        'A sequence of only absent/invalid sources must create no entry');
                    $this->assertArrayNotHasKey($key, $store,
                        'No entry must exist when no valid source was applied');

                    // Re-applying the (all-invalid) sequence still creates nothing.
                    $this->assertSame($store, self::apply_sequence($store, $key, $sources),
                        'Re-applying an all-invalid sequence must remain a no-op');
                    return;
                }

                // Exactly one entry for the pair when any valid source was applied.
                $this->assertCount(1, $store,
                    'Exactly one entry must exist per (userid, cohortid) after recording');
                $this->assertArrayHasKey($key, $store,
                    'The recorded pair must be the single stored entry');

                $stored = $store[$key];

                // The stored value is the max-rank source seen (never downgraded),
                // and is uniquely the strongest source regardless of order.
                $this->assertSame($expectedrank, provenance::rank($stored),
                    'The stored rank must equal the maximum rank over applied valid sources');
                $this->assertSame($expectedvalue, $stored,
                    'The stored value must be the highest-precedence source seen');

                // Idempotence at the value level: merge_source(x, x) == x.
                $this->assertSame($stored, provenance::merge_source($stored, $stored),
                    'merge_source(x, x) must equal x for the stored value');

                // Re-applying the entire sequence a second time changes nothing.
                $this->assertSame($store, self::apply_sequence($store, $key, $sources),
                    'Re-applying the whole sequence must leave the store unchanged');

                // Recording the already-stored value again is also a no-op.
                $this->assertSame($store, self::apply_sequence($store, $key, [$stored]),
                    'Recording the already-stored value must leave the store unchanged');

                // Order independence: deterministic reorderings yield the same store.
                $reversed = array_reverse($sources);
                $this->assertSame($store, self::apply_sequence([], $key, $reversed),
                    'The final value must not depend on the order of the sequence (reversed)');

                $ascending = $sources;
                usort($ascending, static function ($a, $b): int {
                    return provenance::rank($a) <=> provenance::rank($b);
                });
                $this->assertSame($store, self::apply_sequence([], $key, $ascending),
                    'The final value must not depend on the order of the sequence (rank-ascending)');

                $descending = array_reverse($ascending);
                $this->assertSame($store, self::apply_sequence([], $key, $descending),
                    'The final value must not depend on the order of the sequence (rank-descending)');
            });
    }

    /**
     * Pure model of the read-side value closure implemented by
     * provenance::sources_for_users() / get_source().
     *
     * Those resolvers are DB-backed: they read a learner's stored provenance
     * rows (restricted to AFF- cohorts) and fold them to a single API value via
     * the same never-downgrade rule as merge_source(), normalizing any
     * invalid/rank-0 stored value to null. This helper reproduces exactly that
     * fold purely (no DB): starting from null, each stored value is merged in
     * through provenance::merge_source(), which yields the highest-rank valid
     * value seen, or null when none is valid.
     *
     * @param array<int, string|null> $storedvalues The learner's stored source values.
     * @return string|null One of the SOURCE_* constants, or null.
     */
    private static function resolve(array $storedvalues): ?string {
        $resolved = null;
        foreach ($storedvalues as $v) {
            $resolved = provenance::merge_source($resolved, $v);
        }
        return $resolved;
    }

    /**
     * Feature: affiliation-source-provenance, Property 4: Resolved API source is null or exactly one determinable value
     *
     * For any provenance state for a learner (a set of stored source values that
     * may include null and invalid strings), the value the learners endpoint
     * resolves is either null (no determinable record) or exactly one of the
     * three valid constants; an unknown/invalid stored value never leaks through
     * as the API value. When at least one valid source is present the resolved
     * value is non-null and its rank equals the maximum rank over the valid
     * stored values (highest precedence wins). When no valid source is present
     * the resolved value is null. The resolution is deterministic.
     *
     * Validates: Requirements 2.2, 2.3
     */
    public function test_resolved_source_is_null_or_exactly_one_determinable_value(): void {
        $domain = self::source_domain();
        $valid = self::valid_sources();

        $this->limitTo(self::ITERATIONS)
            ->forAll(
                Generator\seq(Generator\elements($domain))
            )
            ->then(function (array $values) use ($valid): void {
                $resolved = self::resolve($values);

                // Independently compute the max rank over the valid stored values.
                $maxrank = 0;
                foreach ($values as $v) {
                    $maxrank = max($maxrank, provenance::rank($v));
                }

                // Value closure: the result is null or exactly one valid constant;
                // an unknown/invalid stored value never leaks through.
                if ($resolved !== null) {
                    $this->assertContains($resolved, $valid,
                        'A non-null resolved source must be one of the three valid constants');
                }

                if ($maxrank > 0) {
                    // At least one valid source present: result is non-null and its
                    // rank equals the maximum rank over the valid stored values.
                    $this->assertNotNull($resolved,
                        'A resolved source must be non-null when any valid source is present');
                    $this->assertSame($maxrank, provenance::rank($resolved),
                        'The resolved rank must equal the max rank over the valid stored values');
                } else {
                    // No valid source present (all null/invalid): result is null.
                    $this->assertNull($resolved,
                        'The resolved source must be null when no valid source is present');
                }

                // Determinism: resolving the same set twice yields the same value.
                $this->assertSame($resolved, self::resolve($values),
                    'resolve() must be deterministic for the same stored values');
            });
    }
}
