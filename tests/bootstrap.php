<?php
/** Dependency-free regression harness; WordPress, SQL and Sensei are test doubles. */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'FKHS_TABLE', 'fkhs_attempts' );
$meta = $contents = $filters = array();
$checks = 0;
$viewer = 7;
$post_types = $post_statuses = $passwords = $enrollment = $view_access = $edit_access = array();
$h5p_available = true;
function check( $condition, $message ) {
    global $checks;
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
}
function get_post_meta( $id, $key, $single = true ) { global $meta; return $meta[$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { global $meta; $meta[$id][$key] = $value; }
function delete_post_meta( $id, $key ) { global $meta; unset( $meta[$id][$key] ); }
function get_post_field( $field, $id ) { global $contents; return $contents[$id] ?? ''; }
function get_current_user_id() { global $viewer; return $viewer; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function get_post_type( $id ) { global $post_types; return $post_types[$id] ?? ($id === 100 ? 'course' : 'lesson'); }
function get_post_status( $id ) { global $post_statuses; return $post_statuses[$id] ?? 'publish'; }
function post_password_required( $id ) { global $passwords; return $passwords[$id] ?? false; }
function __( $text, $domain = null ) { return $text; }
function get_the_title( $id ) { return 'Fixture lesson'; }
function wp_json_encode( $value ) { return json_encode($value); }
function current_time( $type ) { return '2026-11-02 12:00:00'; }
function shortcode_exists( $name ) { global $h5p_available; return $h5p_available; }
// Minimal parser doubles for policy tests. Real parser cases live in wordpress-rest.php.
function get_shortcode_regex( $tags ) { return '(\\[?)\\[(h5p)\\b([^\\]]*)(\/?)\\](?:(.*?)\\[\/h5p\\])?(\\]?)'; }
function shortcode_parse_atts( $text ) {
    preg_match_all('/(?:^|\\s)([\\w-]+)\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s]+))/', $text, $matches, PREG_SET_ORDER);
    $out = array();
    foreach ($matches as $m) $out[$m[1]] = $m[4] ?? ($m[3] ?? $m[2]);
    return $out;
}
function current_user_can( $cap, ...$args ) { return true; }
function user_can( $id, $cap, ...$args ) { global $edit_access; return $id === 99 || ($cap === 'edit_post' && ($edit_access[$id][$args[0]] ?? false)); }
function wp_verify_nonce( ...$args ) { return true; }
function wp_unslash( $v ) { return $v; }
function apply_filters( $tag, $value, ...$args ) { global $filters; return isset($filters[$tag]) ? $filters[$tag]($value, ...$args) : $value; }
function nocache_headers() {}
function do_action( ...$args ) {}
class WP_REST_Request {
    public function __construct( public $lesson, public $payload = null ) {}
    public function get_param( $key ) { return $key === 'lesson_id' ? $this->lesson : null; }
    public function get_json_params() { return $this->payload; }
}
class WP_REST_Response {
    public $headers = array();
    public function __construct( public $data, public $status ) {}
    public function header( $name, $value ) { $this->headers[$name] = $value; }
}
class H5P_Plugin {}
class Sensei_Course {
    public static function is_user_enrolled( $course, $user ) { global $enrollment; return $enrollment[$user][$course] ?? ($user === 7 && $course === 100); }
}
class FixtureLesson {
    public function lesson_quizzes( $lesson ) { return 0; }
    public function get_course_id( $lesson ) { global $meta; return $meta[$lesson]['_lesson_course'] ?? 100; }
}
class FixtureProgress {
    public $started = false;
    public function get_status() { return 'in-progress'; }
    public function get_started_at() { return new DateTimeImmutable('2025-01-01'); }
    public function start($date) { $this->started = $date; }
}
class FixtureProgressRepository {
    public $saved = array();
    public function get($lesson, $user) { return new FixtureProgress(); }
    public function save($progress) { $this->saved[] = $progress; }
}
$sensei = (object) array('lesson'=>new FixtureLesson(), 'lesson_progress_repository'=>new FixtureProgressRepository());
function Sensei() { global $sensei; return $sensei; }
function sensei_can_user_view_lesson( $lesson, $user ) { global $view_access; return $view_access[$user][$lesson] ?? true; }
class Sensei_Utils {
    public static $removed = array();
    public static function sensei_remove_user_from_lesson( $lesson, $user ) { self::$removed[] = array($lesson, $user); }
}
class FakeDB {
    public $prefix = 'wp_';
    public $rows = array();
    public $writes = array();
    public $h5p_ids = array(1, 2);
    public $reads = 0;
    public $insert_id = 0;
    public $last_error = '';
    public $fail_insert = false;
    public function suppress_errors( $value = true ) { return false; }
    public function insert( $table, $data, $formats ) {
        $this->last_error = '';
        if ($this->fail_insert) { $this->last_error = 'fixture insert failure'; return false; }
        $this->insert_id = count($this->writes) + 1;
        $this->writes[] = array_merge($data, array('id'=>$this->insert_id)); return 1;
    }
    public function prepare( $sql, ...$args ) { return array($sql, $args); }
    private function matches( $args ) {
        return array_values(array_filter($this->rows, fn($r) => $r['user_id'] === $args[0] && $r['lesson_id'] === $args[1] && $r['content_id'] === $args[2]));
    }
    public function get_row( $query, $format ) {
        ++$this->reads;
        [$sql, $args] = $query;
        $this->last_error = '';
        if (str_contains($sql, 'event_id=')) {
            foreach ($this->writes as $row) if ($row['user_id'] === $args[0] && $row['lesson_id'] === $args[1] && $row['content_id'] === $args[2] && $row['event_id'] === $args[3]) return $row;
            return null;
        }
        if ( str_contains($sql, 'h5p_contents') ) return array('title'=>'Activity', 'name'=>'H5P.Test', 'major_version'=>1, 'minor_version'=>0);
        $rows = $this->matches($args);
        if ( str_contains($sql, 'raw_score/max_score') ) {
            $rows = array_values(array_filter($rows, fn($r) => $r['max_score'] > 0));
            usort($rows, fn($a, $b) => ($b['raw_score']/$b['max_score'] <=> $a['raw_score']/$a['max_score']) ?: ($b['id'] <=> $a['id']));
        } else {
            check(str_contains($sql, 'created_at DESC, id DESC'), 'Latest SQL must tie-break by ID');
            usort($rows, fn($a, $b) => $b['id'] <=> $a['id']);
        }
        return $rows[0] ?? null;
    }
    public function get_var( $query ) {
        ++$this->reads;
        if (str_contains($query[0], 'h5p_contents')) return in_array($query[1][0], $this->h5p_ids, true) ? $query[1][0] : null;
        foreach ($this->matches($query[1]) as $r) if ($r['completed']) return 1;
        return null;
    }
}
$wpdb = new FakeDB();
$base = __DIR__ . '/../h5p-sensei-bridge/includes/';
foreach (array('helpers', 'progress', 'sensei', 'rest', 'metabox') as $name) require $base . 'class-fkhs-' . $name . '.php';
