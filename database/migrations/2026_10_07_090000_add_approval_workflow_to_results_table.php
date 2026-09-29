<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 13 — Result Approval & Publication: who moved a result through
 * SUBMITTED → APPROVED → PUBLISHED → LOCKED, and when.
 *
 * NO NEW STATUS COLUMN. The workflow reuses `results.status` (Module 12's own column,
 * `ResultStatus`) unchanged, extended with three new enum cases - see the enum's own
 * docblock. There is exactly one lifecycle field for a result; a second one tracking
 * "approval stage" alongside the existing "compilation stage" would let the two disagree
 * with each other for no benefit.
 *
 * FOUR NULLABLE (who, when) PAIRS, one per forward transition - submitted_by/submitted_at,
 * approved_by/approved_at, published_by/published_at, locked_by/locked_at - matching this
 * project's own established "_at" timestamp convention (admissions.decided_at,
 * teacher_assignments.ended_at) for "when did the status change", extended here with a
 * "_by" reference because Module 13's own brief explicitly asks who performed each
 * transition, something no module before this one needed to record. All eight columns are
 * nullable: a freshly compiled result has been through none of these transitions yet, and a
 * result may never progress past COMPILED at all.
 *
 * NO submitted_reason/rejected_at/rejected_by/reverted_at columns. This module implements a
 * strictly forward pipeline - COMPILED → SUBMITTED → APPROVED → PUBLISHED → LOCKED - with no
 * reject-back-to-draft or unpublish transition (see the Module 13 audit §3 for why reversal
 * was considered and deliberately not built). Adding columns for a transition that does not
 * exist would be exactly the speculative schema this project's conventions caution against;
 * a future correction module can add its own columns when a genuine reversal requirement
 * exists to design them against.
 *
 * `nullOnDelete()` on every "_by" column, matching admissions.student_id's own established
 * reasoning: the historical fact that a result WAS submitted/approved/published/locked must
 * outlive the specific user account that performed it, so a user being removed clears the
 * attribution rather than blocking the deletion or cascading into the result itself.
 *
 * No new indexes. Nothing in this module queries "every result a given user approved" or
 * "every result approved on a given date" - the existing `results.status` index (Module 12)
 * already serves the one query this module's own list filter needs ("every SUBMITTED result
 * awaiting my review"), and an index earns its place only once a real query needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');

            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->foreignId('published_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('published_by');

            $table->foreignId('locked_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by');
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn('submitted_at');

            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');

            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn('published_at');

            $table->dropConstrainedForeignId('locked_by');
            $table->dropColumn('locked_at');
        });
    }
};
