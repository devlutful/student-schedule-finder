<?php
/**
 * Plugin Name: Student Schedule Finder
 * Description: Staff-managed student schedules, CSV import and exact full-name lookup.
 * Version: 1.3.0
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Author: Lutful Ahmed
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: student-schedule-finder
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/import-reader.php';

class Lutful_Student_Schedule_Finder {
    const CAP = 'manage_student_schedules';
    const MAX_ROWS = 1000;
    public static function fields() {
        return array('last_name'=>'Last Name', 'first_name'=>'First Name', 'level'=>'Level', 'rehearsal'=>'Dress Rehearsal Assigned Arrival Time', 'show_assigned'=>'Show Assigned', 'class_count'=>'# of Classes', 'dropoff'=>'Show Day Drop Off Time', 'performance'=>'Performance Date/Time', 'details'=>'Details');
    }
    public static function table() { global $wpdb; return $wpdb->prefix . 'student_schedules'; }
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lookup_hash char(64) NOT NULL,
            record_hash char(64) NOT NULL,
            last_name varchar(150) NOT NULL,
            first_name varchar(150) NOT NULL,
            level varchar(255) NOT NULL,
            rehearsal varchar(255) NOT NULL,
            show_assigned varchar(255) NOT NULL,
            class_count varchar(30) NOT NULL,
            dropoff varchar(255) NOT NULL,
            performance varchar(255) NOT NULL,
            details text NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY lookup_hash (lookup_hash),
            UNIQUE KEY record_hash (record_hash)
        ) $charset;");
        $role = get_role('administrator');
        if ($role) { $role->add_cap(self::CAP); }
    }
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_ssl_save', array(__CLASS__, 'save'));
        add_action('admin_post_ssl_delete', array(__CLASS__, 'delete'));
        add_action('wp_ajax_ssl_lookup', array(__CLASS__, 'lookup'));
        add_action('wp_ajax_nopriv_ssl_lookup', array(__CLASS__, 'lookup'));
        add_shortcode('student_lookup', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
    }
    public static function assets() {
        wp_register_style('ssl-lookup', plugins_url('lookup.css', __FILE__), array(), '1.2.0');
        wp_register_script('ssl-lookup', plugins_url('lookup.js', __FILE__), array(), '1.2.0', true);
    }
    public static function normalise($name) {
        // Case-sensitive; preserve accents, punctuation and spelling.
        return trim(preg_replace('/\s+/u', ' ', $name));
    }
    public static function clean_record($source) {
        $data = array();
        foreach (self::fields() as $key=>$label) {
            if (!isset($source[$key]) || !is_scalar($source[$key])) { return new WP_Error('field', 'All nine columns are required.'); }
            $value = (string) $source[$key];
            if (!preg_match('//u', $value)) { return new WP_Error('encoding', 'Use UTF-8 text.'); }
            $value = $key === 'details' ? sanitize_textarea_field($value) : sanitize_text_field($value);
            $limit = $key === 'details' ? 5000 : ($key === 'class_count' ? 30 : (in_array($key, array('first_name','last_name'), true) ? 150 : 255));
            if (strlen($value) > $limit) { return new WP_Error('length', $label . ' exceeds the allowed length.'); }
            $data[$key] = $value;
        }
        if ($data['first_name'] === '' || $data['last_name'] === '') { return new WP_Error('name', 'First and last names are required.'); }
        $data['lookup_hash'] = hash('sha256', self::normalise($data['first_name'].' '.$data['last_name']));
        $values = array();
        foreach (self::fields() as $key=>$label) { $values[] = $data[$key]; }
        $data['record_hash'] = hash('sha256', wp_json_encode($values));
        $data['updated_at'] = current_time('mysql', true);
        return $data;
    }
    private static function guard($action) {
        if (!current_user_can(self::CAP)) { wp_die('Access denied.', '', array('response'=>403)); }
        check_admin_referer($action);
    }
    private static function redirect($message) {
        wp_safe_redirect(add_query_arg(array('page'=>'ssl-students', 'ssl_message'=>$message), admin_url('admin.php'))); exit;
    }
    public static function save() {
        self::guard('ssl_save'); global $wpdb;
        $data = self::clean_record(wp_unslash($_POST));
        if (is_wp_error($data)) { wp_die(esc_html($data->get_error_message())); }
        $id = isset($_POST['record_id']) ? absint($_POST['record_id']) : 0;
        if ($id && !$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE id=%d', $id))) { wp_die('Record not found.'); }
        $result = $id ? $wpdb->update(self::table(), $data, array('id'=>$id)) : $wpdb->insert(self::table(), $data);
        self::redirect($result === false ? 'Unable to save. Check for an identical record.' : 'Student saved.');
    }
    public static function delete() {
        self::guard('ssl_delete'); global $wpdb;
        $id = isset($_POST['record_id']) ? absint($_POST['record_id']) : 0;
        $result = $wpdb->delete(self::table(), array('id'=>$id), array('%d'));
        self::redirect($result === false ? 'Unable to delete.' : 'Student deleted.');
    }
    public static function menu() { add_menu_page('Student Schedules', 'Student Schedules', self::CAP, 'ssl-students', array(__CLASS__, 'admin'), 'dashicons-welcome-learn-more', 30); }
    public static function selected_ids($raw) {
        if (!is_array($raw) || count($raw)>1000) { return new WP_Error('selection','Select between 1 and 1,000 records.'); }
        $ids=array();
        foreach($raw as $id) {
            if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<1) { return new WP_Error('selection','Invalid record selection.'); }
            $ids[]=(int)$id;
        }
        $ids=array_values(array_unique($ids));
        return $ids ? $ids : new WP_Error('selection','Select at least one student.');
    }
    public static function bulk_changes($enabled,$values) {
        if (!is_array($enabled) || !is_array($values)) { return new WP_Error('fields','Invalid bulk edit fields.'); }
        $allowed=self::fields(); unset($allowed['first_name'],$allowed['last_name']); $changes=array();
        foreach($enabled as $key) {
            if (!is_string($key) || !isset($allowed[$key]) || !isset($values[$key]) || !is_scalar($values[$key])) { return new WP_Error('fields','Invalid bulk edit field.'); }
            $changes[$key]=(string)$values[$key];
        }
        return $changes ? $changes : new WP_Error('fields','Tick at least one field to change.');
    }
    private static function bulk_stage() {
        self::guard('lssf_bulk'); global $wpdb;
        $ids=self::selected_ids(isset($_POST['student_ids']) ? $_POST['student_ids'] : array());
        if (is_wp_error($ids)) { echo '<div class="notice notice-error"><p>'.esc_html($ids->get_error_message()).'</p></div>'; return false; }
        $action=isset($_POST['bulk_action']) && is_string($_POST['bulk_action']) ? $_POST['bulk_action'] : '';
        if (!in_array($action,array('edit','delete'),true)) { echo '<p>Select bulk edit or bulk delete.</p>'; return false; }
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id IN ('.$placeholders.') ORDER BY last_name, first_name',$ids),ARRAY_A);
        if (count($rows)!==count($ids)) { echo '<p>Some selected records no longer exist. Refresh the student list.</p>'; return false; }
        $stage=isset($_POST['lssf_bulk_stage']) && is_string($_POST['lssf_bulk_stage']) ? $_POST['lssf_bulk_stage'] : '';
        if ($stage==='execute') {
            $expected=isset($_POST['expected_hashes']) && is_array($_POST['expected_hashes']) ? $_POST['expected_hashes'] : array();
            foreach($rows as $row) {
                if (!isset($expected[$row['id']]) || !is_string($expected[$row['id']]) || !hash_equals($row['record_hash'],$expected[$row['id']])) {
                    echo '<p>A selected record changed after your preview. No changes were applied. Start again from the student list.</p>'; return false;
                }
            }
            if ($action==='delete') {
                if (empty($_POST['confirm_bulk_delete']) || $_POST['confirm_bulk_delete']!=='yes') { echo '<p>Confirm the deletion before continuing.</p>'; return false; }
                $count=$wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE id IN ('.$placeholders.')',$ids));
                echo '<div class="notice notice-info"><p>'.esc_html($count===false ? 'Deletion failed. No success count is available; check the database error log.' : 'Deleted '.$count.' selected students.').'</p></div>'; return false;
            }
            $changes=self::bulk_changes(isset($_POST['enabled_fields']) ? $_POST['enabled_fields'] : array(),isset($_POST['bulk_values']) ? wp_unslash($_POST['bulk_values']) : array());
            if (is_wp_error($changes)) { echo '<p>'.esc_html($changes->get_error_message()).' Use your browser’s Back button to keep the selection.</p>'; return true; }
            $updates=array();
            foreach($rows as $row) {
                $updated=self::clean_record(array_merge($row,$changes));
                if (is_wp_error($updated)) { echo '<p>'.esc_html($updated->get_error_message()).' No rows were changed. Use Back to correct the fields.</p>'; return true; }
                $updates[$row['id']]=$updated;
            }
            // Detect collisions before saving, including another selected row converging to identical data.
            $hashes=array();
            foreach($updates as $id=>$updated) {
                $existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE record_hash=%s AND id<>%d',$updated['record_hash'],$id));
                if (isset($hashes[$updated['record_hash']]) || $existing) { echo '<p>The edit would create an identical record. No rows were changed. Edit these students individually.</p>'; return true; }
                $hashes[$updated['record_hash']]=true;
            }
            $saved=0; $failed=0;
            foreach($updates as $id=>$updated) {
                if ($wpdb->update(self::table(),$updated,array('id'=>$id),null,array('%d'))===false) { $failed++; } else { $saved++; }
            }
            echo '<div class="notice notice-info"><p>'.esc_html('Saved: '.$saved.'. Failed: '.$failed.'.').'</p></div>';
            if ($failed) { echo '<p>Some changes failed. This operation is not transactional; successful updates remain saved. Check the database error log before retrying.</p>'; }
            return false;
        }
        if ($stage!=='preview') { echo '<p>Invalid bulk action stage.</p>'; return false; }
        echo '<h2>'.($action==='delete' ? 'Confirm bulk deletion' : 'Bulk edit selected students').'</h2><p>'.esc_html(count($rows)).' selected students:</p><ul>';
        foreach($rows as $row) { echo '<li>'.esc_html($row['first_name'].' '.$row['last_name'].' — '.$row['level'].' / '.$row['show_assigned']).'</li>'; }
        echo '</ul><form method="post" action="'.esc_url(admin_url('admin.php?page=ssl-students')).'">'; wp_nonce_field('lssf_bulk');
        echo '<input type="hidden" name="lssf_bulk_stage" value="execute"><input type="hidden" name="bulk_action" value="'.esc_attr($action).'">';
        foreach($ids as $id) { echo '<input type="hidden" name="student_ids[]" value="'.esc_attr($id).'">'; }
        foreach($rows as $row) { echo '<input type="hidden" name="expected_hashes['.esc_attr($row['id']).']" value="'.esc_attr($row['record_hash']).'">'; }
        if ($action==='delete') {
            echo '<p>Deletion permanently removes the selected records from the active database. Backups may retain copies.</p><label><input type="checkbox" name="confirm_bulk_delete" value="yes" required> Delete these selected students</label>';
            submit_button('Delete selected students','delete');
        } else {
            echo '<p>Tick each field you want to replace. The value will apply to every selected student. Unticked fields stay unchanged. A ticked blank value clears that field. Names must be edited individually.</p><table class="form-table">';
            foreach(self::fields() as $key=>$label) {
                if (in_array($key,array('first_name','last_name'),true)) { continue; }
                echo '<tr><th><label><input type="checkbox" name="enabled_fields[]" value="'.esc_attr($key).'"> '.esc_html($label).'</label></th><td>';
                if ($key==='details') { echo '<textarea aria-label="New details" name="bulk_values[details]" rows="3" class="large-text" maxlength="5000"></textarea>'; }
                else { echo '<input aria-label="'.esc_attr('New '.$label).'" type="text" name="bulk_values['.esc_attr($key).']" class="regular-text" maxlength="'.($key==='class_count' ? '30' : '255').'">'; }
                echo '</td></tr>';
            }
            echo '</table>'; submit_button('Apply changes to selected students');
        }
        echo '</form><a class="button" href="'.esc_url(admin_url('admin.php?page=ssl-students')).'">Cancel</a>'; return true;
    }
    public static function admin() {
        if (!current_user_can(self::CAP)) { return; }
        global $wpdb;
        echo '<div class="wrap"><h1>Student Schedules</h1><p>Shortcode: <code>[student_lookup]</code>. Full-name lookup is public and case-sensitive. Duplicate full names are withheld. Dates and times are displayed exactly as entered.</p>';
        $table_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like(self::table())));
        if (!$table_exists) {
            self::activate();
            $table_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like(self::table())));
            if (!$table_exists) { echo '<div class="notice notice-error"><p>The student database table could not be created. Ask your host to check CREATE/ALTER database permissions and the WordPress database error log.</p></div></div>'; return; }
        }
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD']==='POST' && empty($_POST)) { echo '<div class="notice notice-error"><p>The upload exceeds the server post_max_size limit. Use a smaller file or ask your host to increase the upload limits.</p></div>'; }
        if (isset($_GET['ssl_message']) && is_string($_GET['ssl_message'])) { echo '<div class="notice notice-info"><p>'.esc_html(wp_unslash($_GET['ssl_message'])).'</p></div>'; }
        if (isset($_POST['lssf_bulk_stage']) && self::bulk_stage()) { echo '</div>'; return; }
        if (isset($_POST['ssl_import_stage'])) {
            self::guard('ssl_import');
            self::import_stage();
        }
        $editing = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $row = $editing ? $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d', $editing), ARRAY_A) : array();
        if (!$row) { $row = array(); $editing = 0; }
        echo '<h2>'.($editing ? 'Edit student' : 'Add student').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ssl_save"><input type="hidden" name="record_id" value="'.esc_attr($editing).'">';
        wp_nonce_field('ssl_save');
        echo '<table class="form-table"><tbody>';
        foreach (self::fields() as $key=>$label) {
            $value = isset($row[$key]) ? $row[$key] : '';
            echo '<tr><th><label for="ssl-'.esc_attr($key).'">'.esc_html($label).'</label></th><td>';
            if ($key==='details') { echo '<textarea class="large-text" rows="3" id="ssl-details" name="details" maxlength="5000">'.esc_textarea($value).'</textarea>'; }
            else { echo '<input class="regular-text" type="text" id="ssl-'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($value).'" '.(in_array($key,array('first_name','last_name'),true) ? 'required maxlength="150"' : 'maxlength="'.($key==='class_count' ? '30' : '255').'"').'>'; }
            echo '</td></tr>';
        }
        echo '</tbody></table>'; submit_button('Save student'); echo '</form>';
        echo '<h2>Import CSV or Excel</h2><p>Upload CSV or .xlsx, maximum 2 MB and 1,000 students per import. Excel reads the first worksheet. The nine sheet headings are required, in any order. Blank rows are ignored. Identical rows are skipped; existing records are not overwritten. Preview and then confirm.</p><form method="post" enctype="multipart/form-data">';
        wp_nonce_field('ssl_import');
        echo '<input type="hidden" name="ssl_import_stage" value="preview"><input type="file" name="csv" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>'; submit_button('Preview import', 'secondary'); echo '</form>';
        $page = max(1, isset($_GET['ssl_paged']) ? absint($_GET['ssl_paged']) : 1);
        $search = isset($_GET['student_search']) && is_string($_GET['student_search']) ? sanitize_text_field(wp_unslash($_GET['student_search'])) : '';
        $where = '';
        if ($search !== '') { $like='%'.$wpdb->esc_like($search).'%'; $where=$wpdb->prepare(' WHERE first_name LIKE %s OR last_name LIKE %s', $like, $like); }
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM '.self::table().$where);
        $pages = max(1, (int) ceil($total/25)); $page=min($page,$pages);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().$where.' ORDER BY last_name, first_name, id LIMIT %d OFFSET %d', 25, ($page-1)*25), ARRAY_A);
        wp_enqueue_script('lssf-admin',plugins_url('admin.js',__FILE__),array(),'1.3.0',true);
        echo '<h2>Students ('.esc_html($total).')</h2><form method="get"><input type="hidden" name="page" value="ssl-students"><input aria-label="Search students" name="student_search" value="'.esc_attr($search).'"> <button class="button">Search</button></form>';
        echo '<form id="lssf-bulk" method="post" action="'.esc_url(admin_url('admin.php?page=ssl-students')).'" style="margin:12px 0">'; wp_nonce_field('lssf_bulk');
        echo '<input type="hidden" name="lssf_bulk_stage" value="preview"><label for="lssf-action" class="screen-reader-text">Bulk action</label><select id="lssf-action" name="bulk_action" required><option value="">Bulk actions</option><option value="edit">Bulk edit</option><option value="delete">Bulk delete</option></select> <button class="button">Continue</button> <span id="lssf-selection" role="status">0 selected</span><p>Select all applies only to the current page. Selection is reset when navigating between pages.</p></form><div style="overflow:auto"><table class="widefat striped"><thead><tr><th><input id="lssf-select-all" type="checkbox" aria-label="Select all students on this page"></th>';
        foreach (self::fields() as $label) { echo '<th>'.esc_html($label).'</th>'; } echo '<th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><input class="lssf-select" form="lssf-bulk" type="checkbox" name="student_ids[]" value="'.esc_attr($r['id']).'" aria-label="'.esc_attr('Select '.$r['first_name'].' '.$r['last_name']).'"></td>'; foreach (self::fields() as $key=>$label) { echo '<td>'.esc_html($r[$key]).'</td>'; }
            echo '<td><a href="'.esc_url(add_query_arg(array('page'=>'ssl-students','edit'=>$r['id']),admin_url('admin.php'))).'">Edit</a><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ssl_delete"><input type="hidden" name="record_id" value="'.esc_attr($r['id']).'">'; wp_nonce_field('ssl_delete');
            echo '<label><input type="checkbox" required> Confirm deletion</label> <button class="button">Delete</button></form></td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="11">No students found.</td></tr>'; }
        echo '</tbody></table></div><p>Page '.esc_html($page).' of '.esc_html($pages).'</p>';
        foreach (array('Previous'=>$page-1,'Next'=>$page+1) as $label=>$target) { if ($target>=1 && $target<=$pages) { echo '<a class="button" href="'.esc_url(add_query_arg(array('page'=>'ssl-students','ssl_paged'=>$target,'student_search'=>$search),admin_url('admin.php'))).'">'.esc_html($label).'</a> '; } }
        echo '</div>';
    }
    public static function parse_csv($file) {
        $text=file_get_contents($file);
        if ($text===false) { return new WP_Error('file','Cannot read CSV.'); }
        if (substr($text,0,2)==="\xFF\xFE" || substr($text,0,2)==="\xFE\xFF") {
            if (!function_exists('iconv')) { return new WP_Error('encoding','Save the file as CSV UTF-8, or enable PHP iconv.'); }
            $encoding=substr($text,0,2)==="\xFF\xFE" ? 'UTF-16LE' : 'UTF-16BE';
            $text=iconv($encoding,'UTF-8',substr($text,2));
            if ($text===false) { return new WP_Error('encoding','Cannot decode the CSV. Save as CSV UTF-8.'); }
        }
        $text=preg_replace('/^\xEF\xBB\xBF/','',$text);
        $text=str_replace(array("\r\n","\r"),"\n",$text);
        if (!preg_match('//u',$text)) { return new WP_Error('encoding','Save the file as CSV UTF-8.'); }
        $sample=strtok($text,"\n"); $delimiter=',';
        foreach (array(',', ';', "\t") as $candidate) { if (count(str_getcsv((string)$sample,$candidate,'"','\\'))>=9) { $delimiter=$candidate; break; } }
        $handle=fopen('php://temp','w+b'); fwrite($handle,$text); rewind($handle); $rows=array();
        while (($values=fgetcsv($handle,0,$delimiter,'"','\\'))!==false) {
            if (!array_filter($values,function($v){return trim((string)$v)!=='';})) { continue; }
            $rows[]=$values;
            if (count($rows)>self::MAX_ROWS+1) { fclose($handle); return new WP_Error('limit','File exceeds 1,000 students.'); }
        }
        fclose($handle); return self::parse_rows($rows);
    }
    public static function parse_rows($rows) {
        if (!$rows) { return new WP_Error('empty','File contains no student data.'); }
        $header=array_shift($rows); $map=array();
        while (count($header)>9 && trim((string)end($header))==='') { array_pop($header); }
        $expected=array(); foreach(self::fields() as $key=>$label) { $expected[self::header_key($label)]=$key; }
        foreach ($header as $index=>$label) {
            $normal=self::header_key($label);
            if (!isset($expected[$normal])) { return new WP_Error('header','Unrecognised column "'.sanitize_text_field($label).'". Use the nine demo sheet headings.'); }
            $key=$expected[$normal];
            if (isset($map[$key])) { return new WP_Error('header','Duplicate column: '.$label); }
            $map[$key]=$index;
        }
        if (count($map)!==9) { return new WP_Error('header','Missing columns. Required: '.implode(', ',array_values(self::fields()))); }
        $records=array(); $errors=array();
        foreach ($rows as $index=>$values) {
            while (count($values)>count($header) && trim((string)end($values))==='') { array_pop($values); }
            if (count($values)!==count($header)) { $errors[]='Data row '.($index+1).': expected nine columns.'; continue; }
            $source=array(); foreach($map as $key=>$column) { $source[$key]=(string)$values[$column]; }
            $record=self::clean_record($source);
            if (is_wp_error($record)) { $errors[]='Data row '.($index+1).': '.$record->get_error_message(); }
            else { $records[]=$record; }
        }
        return array('records'=>$records,'errors'=>$errors);
    }
    private static function header_key($label) {
        return strtolower(trim(preg_replace('/\s+/u',' ',preg_replace('/^\xEF\xBB\xBF/','',(string)$label))));
    }
    private static function import_stage() {
        global $wpdb;
        $key='ssl_import_'.get_current_user_id();
        if ($_POST['ssl_import_stage']==='commit') {
            $pending=get_transient($key);
            $token=isset($_POST['import_token']) && is_string($_POST['import_token']) ? wp_unslash($_POST['import_token']) : '';
            if (!$pending || !hash_equals($pending['token'],$token)) { echo '<div class="notice notice-error"><p>Preview expired or replaced. Upload the CSV again.</p></div>'; return; }
            delete_transient($key);
            if ($pending['errors']) { echo '<p>Fix the invalid rows before importing.</p>'; return; }
            $added=0; $skipped=0; $failed=0;
            foreach ($pending['records'] as $data) {
                $exists=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE record_hash=%s', $data['record_hash']));
                if ($exists) { $skipped++; continue; }
                $data['updated_at']=current_time('mysql',true);
                if ($wpdb->insert(self::table(),$data)===false) { $failed++; } else { $added++; }
            }
            echo '<div class="notice notice-info"><p>'.esc_html("Imported: $added. Identical rows skipped: $skipped. Failed: $failed.").'</p></div>';
            if ($failed) { echo '<p>Some records could not be saved. Check the WordPress database error log and database write permissions. Retry after correcting the issue; identical saved rows will be skipped.</p>'; }
            return;
        }
        delete_transient($key);
        if (!isset($_FILES['csv']) || !is_array($_FILES['csv']) || !isset($_FILES['csv']['error']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK) { echo '<p>Upload failed. Check the server upload_max_filesize and post_max_size settings, and select the file again.</p>'; return; }
        $ext=strtolower(pathinfo($_FILES['csv']['name'],PATHINFO_EXTENSION));
        if ($_FILES['csv']['size']>2*1024*1024 || !in_array($ext,array('csv','xlsx'),true) || !is_uploaded_file($_FILES['csv']['tmp_name'])) { echo '<p>Upload a CSV or .xlsx file no larger than 2 MB. Legacy .xls files must be saved as .xlsx first.</p>'; return; }
        $parsed=$ext==='xlsx' ? Lutful_Student_Schedule_Import_Reader::xlsx($_FILES['csv']['tmp_name']) : self::parse_csv($_FILES['csv']['tmp_name']);
        @unlink($_FILES['csv']['tmp_name']);
        if (is_wp_error($parsed)) { echo '<p>'.esc_html($parsed->get_error_message()).'</p>'; return; }
        $parsed['token']=wp_generate_password(32,false,false);
        if (!set_transient($key,$parsed,10*MINUTE_IN_SECONDS)) { echo '<p>Cannot save the import preview. Check WordPress database/object-cache availability.</p>'; return; }
        echo '<h2>Import preview</h2><p>'.esc_html(count($parsed['records'])).' valid rows. '.esc_html(count($parsed['errors'])).' invalid rows. Preview shows up to 10 rows.</p>';
        foreach (array_slice($parsed['errors'],0,20) as $error) { echo '<p>'.esc_html($error).'</p>'; }
        echo '<div style="overflow:auto"><table class="widefat"><thead><tr>'; foreach (self::fields() as $label) { echo '<th>'.esc_html($label).'</th>'; } echo '</tr></thead><tbody>';
        foreach (array_slice($parsed['records'],0,10) as $r) { echo '<tr>'; foreach (self::fields() as $k=>$label) { echo '<td>'.esc_html($r[$k]).'</td>'; } echo '</tr>'; } echo '</tbody></table></div>';
        if (!$parsed['errors'] && $parsed['records']) {
            echo '<form method="post">'; wp_nonce_field('ssl_import'); echo '<input type="hidden" name="ssl_import_stage" value="commit"><input type="hidden" name="import_token" value="'.esc_attr($parsed['token']).'">'; submit_button('Confirm import'); echo '</form>';
        }
    }
    public static function shortcode() {
        wp_enqueue_style('ssl-lookup'); wp_enqueue_script('ssl-lookup');
        $id=wp_unique_id('ssl-name-');
        return '<section class="ssl-lookup" data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'"><h2>Find your student’s schedule</h2><p>Enter their full first and last name exactly as registered.</p><form class="ssl-form"><label for="'.esc_attr($id).'">Student’s full name</label><div class="ssl-controls"><input id="'.esc_attr($id).'" name="student_name" type="text" placeholder="Alex Smith" maxlength="301" autocomplete="off" required><button type="submit">Find schedule</button></div></form><p class="ssl-status" role="status" aria-live="polite"></p><div class="ssl-result"></div><noscript>Please enable JavaScript to search.</noscript></section>';
    }
    public static function lookup() {
        nocache_headers(); header('Cache-Control: no-store, private, max-age=0'); header('X-Robots-Tag: noindex, nofollow');
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD']!=='POST') { wp_send_json_error(array('message'=>'Use the search form.'),405); }
        // Trust REMOTE_ADDR only. Reverse-proxy deployments need a host-level rate limit as well.
        $ip=isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key='ssl_rate_'.hash_hmac('sha256',$ip,wp_salt('auth'));
        $now=time(); $rate=get_transient($key);
        if (!$rate || $rate['until']<=$now) { $rate=array('count'=>0,'until'=>$now+300); }
        if ($rate['count']>=10) { header('Retry-After: '.max(1,$rate['until']-$now)); wp_send_json_error(array('message'=>'Too many searches. Please try again in a few minutes.'),429); }
        $rate['count']++; set_transient($key,$rate,max(1,$rate['until']-$now));
        $raw=isset($_POST['student_name']) && is_string($_POST['student_name']) ? wp_unslash($_POST['student_name']) : '';
        if (strlen($raw)>301 || !preg_match('//u',$raw)) { wp_send_json_error(array('message'=>'Enter the full first and last name.'),400); }
        $name=self::normalise(sanitize_text_field($raw));
        if ($name==='' || strpos($name,' ')===false) { wp_send_json_error(array('message'=>'Enter the full first and last name.'),400); }
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE lookup_hash=%s LIMIT 2',hash('sha256',$name)),ARRAY_A);
        if ($wpdb->last_error) { wp_send_json_error(array('message'=>'Search is temporarily unavailable. Please contact the office.'),503); }
        if (count($rows)!==1 || self::normalise($rows[0]['first_name'].' '.$rows[0]['last_name'])!==$name) {
            wp_send_json_error(array('message'=>'Unable to find a unique matching student. Check the full name and capitalisation, or contact the office.'));
        }
        $result=array(); foreach (self::fields() as $key=>$label) { $result[$key]=$rows[0][$key]; }
        wp_send_json_success(array('student'=>$result));
    }
}
register_activation_hook(__FILE__, array('Lutful_Student_Schedule_Finder','activate'));
Lutful_Student_Schedule_Finder::init();
