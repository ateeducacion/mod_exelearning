<?php
// This file is part of Moodle - http://moodle.org/
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

namespace mod_exelearning\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use mod_exelearning\local\attempts;

/**
 * Privacy provider tests for mod_exelearning.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass exelearning instance. */
    protected $instance;

    /** @var \context_module the module context. */
    protected $context;

    /** @var \stdClass student with attempts. */
    protected $student1;

    /** @var \stdClass second student with attempts. */
    protected $student2;

    /** @var string component name. */
    protected $component = 'mod_exelearning';

    /**
     * Create a course, an exelearning instance, two students and seed attempts.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $this->instance = $generator->create_instance(['course' => $course->id]);

        $cm = get_coursemodule_from_instance('exelearning', $this->instance->id);
        $this->context = \context_module::instance($cm->id);

        $this->student1 = $this->getDataGenerator()->create_user();
        $this->student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->student2->id, $course->id, 'student');

        foreach ([$this->student1, $this->student2] as $student) {
            attempts::record_item($this->instance->id, $student->id, 1, 0, 5, 10, 'completed', 't0');
            attempts::record_item($this->instance->id, $student->id, 1, 1, 7, 10, 'completed', 't0');
        }
    }

    /**
     * The module context is returned for a user that has attempts.
     */
    public function test_get_contexts_for_userid(): void {
        $contextlist = provider::get_contexts_for_userid($this->student1->id);
        $this->assertCount(1, $contextlist);
        $this->assertEquals($this->context->id, $contextlist->get_contextids()[0]);

        // A user with no attempts has no contexts.
        $stranger = $this->getDataGenerator()->create_user();
        $empty = provider::get_contexts_for_userid($stranger->id);
        $this->assertCount(0, $empty);
    }

    /**
     * Both students with attempts are listed for the module context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, $this->component);
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertCount(2, $userids);
        $this->assertContains((int) $this->student1->id, array_map('intval', $userids));
        $this->assertContains((int) $this->student2->id, array_map('intval', $userids));
    }

    /**
     * Exported data contains the user's attempts in the module context.
     */
    public function test_export_user_data(): void {
        $this->export_context_data_for_user($this->student1->id, $this->context, $this->component);

        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([]);
        $this->assertNotEmpty($data->attempts);
        $this->assertCount(2, $data->attempts);
        $this->assertArrayHasKey('xapiweight', $data->attempts[0]);
        $this->assertArrayHasKey('xapiorder', $data->attempts[0]);
    }

    /**
     * Deleting a single user's data leaves the other student untouched.
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $approved = new approved_contextlist($this->student1, $this->component, [$this->context->id]);
        provider::delete_data_for_user($approved);

        $this->assertSame(0, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id, 'userid' => $this->student1->id]
        ));
        $this->assertSame(2, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id, 'userid' => $this->student2->id]
        ));
    }

    /**
     * Deleting all data in the context wipes every user's attempts.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id]
        ));
    }

    /**
     * Deleting a set of approved users removes only those users' attempts.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $approved = new approved_userlist($this->context, $this->component, [$this->student1->id]);
        provider::delete_data_for_users($approved);

        $this->assertSame(0, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id, 'userid' => $this->student1->id]
        ));
        $this->assertSame(2, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $this->instance->id, 'userid' => $this->student2->id]
        ));
    }

    /**
     * get_metadata() declares the attempt table, the instance table and the
     * gradebook data flow.
     */
    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection('mod_exelearning');
        $collection = provider::get_metadata($collection);

        $names = array_map(static fn($item) => $item->get_name(), $collection->get_collection());
        $this->assertContains('exelearning_attempt', $names);
        $this->assertContains('exelearning', $names);
        $this->assertContains('exelearning_migration', $names);
    }

    /**
     * The provider safely ignores contexts it does not handle (neither the module
     * context with attempts nor the system context with migration audit rows).
     */
    public function test_unhandled_contexts_are_ignored(): void {
        $course = \context_course::instance($this->instance->course);

        // A context the provider does not handle deletes nothing and adds no users.
        provider::delete_data_for_all_users_in_context($course);

        $userlist = new userlist($course, $this->component);
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist->get_userids());
    }

    /**
     * Export and per-user deletion skip contexts the provider does not handle
     * without touching any stored data.
     */
    public function test_export_and_delete_skip_unhandled_context(): void {
        global $DB;
        $course = \context_course::instance($this->instance->course);
        $user = $this->getDataGenerator()->create_user();
        $before = $DB->count_records('exelearning_attempt');

        $approved = new approved_contextlist($user, $this->component, [$course->id]);
        provider::export_user_data($approved);
        provider::delete_data_for_user($approved);

        $userlist = new approved_userlist($course, $this->component, [$user->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame($before, $DB->count_records('exelearning_attempt'));
    }

    /**
     * Insert a migration audit row attributed to the given manager.
     *
     * @param int $userid The manager who ran the migration.
     * @param int $sourcecmid Source course module id (kept unique by the table index).
     * @return int The inserted record id.
     */
    protected function seed_migration(int $userid, int $sourcecmid): int {
        global $DB;
        return (int) $DB->insert_record('exelearning_migration', (object) [
            'sourcecomponent' => 'mod_exeweb',
            'sourcecmid'      => $sourcecmid,
            'targetcmid'      => $sourcecmid + 1000,
            'userid'          => $userid,
            'timecreated'     => 1700000000,
            'timemodified'    => 1700000000,
        ]);
    }

    /**
     * A manager who ran a migration is found at the system context.
     */
    public function test_migration_get_contexts_for_userid(): void {
        $manager = $this->getDataGenerator()->create_user();
        $this->seed_migration((int) $manager->id, 11);

        $contextlist = provider::get_contexts_for_userid($manager->id);
        $contextids = array_map('intval', $contextlist->get_contextids());
        $this->assertContains(\context_system::instance()->id, $contextids);
    }

    /**
     * Managers with migration rows are listed for the system context; the
     * anonymised sentinel (userid 0) is not.
     */
    public function test_migration_get_users_in_system_context(): void {
        $manager1 = $this->getDataGenerator()->create_user();
        $manager2 = $this->getDataGenerator()->create_user();
        $this->seed_migration((int) $manager1->id, 21);
        $this->seed_migration((int) $manager2->id, 22);
        $this->seed_migration(0, 23);

        $system = \context_system::instance();
        $userlist = new userlist($system, $this->component);
        provider::get_users_in_context($userlist);

        $userids = array_map('intval', $userlist->get_userids());
        $this->assertContains((int) $manager1->id, $userids);
        $this->assertContains((int) $manager2->id, $userids);
        $this->assertNotContains(0, $userids);
    }

    /**
     * A manager's migration rows are exported in the system context.
     */
    public function test_migration_export_user_data(): void {
        $manager = $this->getDataGenerator()->create_user();
        $this->seed_migration((int) $manager->id, 31);

        $system = \context_system::instance();
        $this->export_context_data_for_user($manager->id, $system, $this->component);

        $writer = writer::with_context($system);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('privacy:path:migrations', 'mod_exelearning')]);
        $this->assertNotEmpty($data->migrations);
        $this->assertCount(1, $data->migrations);
    }

    /**
     * Erasing a manager anonymises (userid = 0) their migration rows but keeps the
     * audit/idempotency rows, leaving other managers untouched.
     */
    public function test_migration_delete_for_user_anonymises(): void {
        global $DB;
        $manager1 = $this->getDataGenerator()->create_user();
        $manager2 = $this->getDataGenerator()->create_user();
        $this->seed_migration((int) $manager1->id, 41);
        $this->seed_migration((int) $manager2->id, 42);
        $total = $DB->count_records('exelearning_migration');

        $system = \context_system::instance();
        $approved = new approved_contextlist($manager1, $this->component, [$system->id]);
        provider::delete_data_for_user($approved);

        // The row survives (idempotency map preserved) but is no longer attributed.
        $this->assertSame($total, $DB->count_records('exelearning_migration'));
        $this->assertSame(0, $DB->count_records('exelearning_migration', ['userid' => $manager1->id]));
        $this->assertSame(1, $DB->count_records('exelearning_migration', ['userid' => $manager2->id]));
    }

    /**
     * delete_data_for_users anonymises the named managers' migration rows only.
     */
    public function test_migration_delete_for_users_anonymises(): void {
        global $DB;
        $manager1 = $this->getDataGenerator()->create_user();
        $manager2 = $this->getDataGenerator()->create_user();
        $this->seed_migration((int) $manager1->id, 51);
        $this->seed_migration((int) $manager2->id, 52);

        $system = \context_system::instance();
        $userlist = new approved_userlist($system, $this->component, [$manager1->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $DB->count_records('exelearning_migration', ['userid' => $manager1->id]));
        $this->assertSame(1, $DB->count_records('exelearning_migration', ['userid' => $manager2->id]));
    }

    /**
     * delete_data_for_all_users_in_context at system level anonymises every
     * migration row while preserving the audit map.
     */
    public function test_migration_delete_all_in_system_anonymises(): void {
        global $DB;
        $manager1 = $this->getDataGenerator()->create_user();
        $manager2 = $this->getDataGenerator()->create_user();
        $this->seed_migration((int) $manager1->id, 61);
        $this->seed_migration((int) $manager2->id, 62);
        $total = $DB->count_records('exelearning_migration');

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame($total, $DB->count_records('exelearning_migration'));
        $this->assertSame($total, $DB->count_records('exelearning_migration', ['userid' => 0]));
    }

    /**
     * Cross-context isolation: a module-context erasure removes attempts but must
     * NOT touch the system-level migration audit rows.
     */
    public function test_module_erasure_leaves_migration_intact(): void {
        global $DB;
        $manager = $this->getDataGenerator()->create_user();
        $this->seed_migration((int) $manager->id, 71);

        // Every module-context erasure entry point (all operate on attempts only).
        provider::delete_data_for_all_users_in_context($this->context);
        $approved = new approved_contextlist($this->student1, $this->component, [$this->context->id]);
        provider::delete_data_for_user($approved);
        $userlist = new approved_userlist($this->context, $this->component, [$this->student2->id]);
        provider::delete_data_for_users($userlist);

        // The migration row and its attribution survive a module-context erasure.
        $this->assertSame(1, $DB->count_records('exelearning_migration', ['userid' => $manager->id]));
    }

    /**
     * Cross-context isolation: a system-context erasure anonymises migration rows
     * but must NOT touch the module-level attempt history.
     */
    public function test_system_erasure_leaves_attempts_intact(): void {
        global $DB;
        $manager = $this->getDataGenerator()->create_user();
        $this->seed_migration((int) $manager->id, 81);
        $before = $DB->count_records('exelearning_attempt');

        $system = \context_system::instance();
        // Every system-context erasure entry point (all operate on migration only).
        $approved = new approved_contextlist($manager, $this->component, [$system->id]);
        provider::delete_data_for_user($approved);
        provider::delete_data_for_all_users_in_context($system);
        $userlist = new approved_userlist($system, $this->component, [$manager->id]);
        provider::delete_data_for_users($userlist);

        // Attempts are untouched by a system-context erasure; migration is anonymised.
        $this->assertSame($before, $DB->count_records('exelearning_attempt'));
        $this->assertSame(0, $DB->count_records('exelearning_migration', ['userid' => $manager->id]));
    }
}
