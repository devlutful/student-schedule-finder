<?php
// Standalone parser regressions, not a substitute for WordPress integration tests.
define('ABSPATH', __DIR__.'/');
class WP_Error {
    private $message;
    public function __construct($code,$message) { $this->message=$message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_text_field($v) { return trim(preg_replace('/\s+/u',' ',strip_tags($v))); }
function sanitize_textarea_field($v) { return trim(strip_tags($v)); }
function wp_json_encode($v) { return json_encode($v); }
function current_time($format,$gmt=false) { return '2026-10-06 00:00:00'; }
function add_action() {}
function add_shortcode() {}
function register_activation_hook() {}
require dirname(__DIR__).'/student-schedule-finder.php';
function expect($ok,$message) { if (!$ok) { throw new Exception($message); } }
$fields=Lutful_Student_Schedule_Finder::fields();
$header=array_values($fields);
// Completely fictional fixture; no uploaded student records.
$student=array('Student','Example','Sample Level','2035-01-02 16:00:00','Sample Show','2','Example drop-off','Example performance','Fictional sample instructions; Test only;');
foreach(array(',', ';', "\t") as $delimiter) {
    $file=tempnam(sys_get_temp_dir(),'lssf'); $h=fopen($file,'wb');
    fwrite($h,"\xEF\xBB\xBF");
    fputcsv($h,array_map('strtoupper',$header),$delimiter);
    for($i=0;$i<1100;$i++) { fwrite($h,"\n"); }
    fputcsv($h,$student,$delimiter);fclose($h);
    $result=Lutful_Student_Schedule_Finder::parse_csv($file);unlink($file);
    expect(!is_wp_error($result),'Delimiter and blank rows');
    expect(count($result['records'])===1 && !$result['errors'],'Exactly one record');
    expect($result['records'][0]['details']===$student[8],'Delimiter inside quoted instructions');
}
$bad=$header;$bad[1]=$bad[0];
expect(is_wp_error(Lutful_Student_Schedule_Finder::parse_rows(array($bad,$student))),'Duplicate header must fail');
$bad=$student;$bad[1]='';
$result=Lutful_Student_Schedule_Finder::parse_rows(array($header,$bad));
expect(count($result['errors'])===1,'Missing first name rejected');
expect(Lutful_Student_Schedule_Finder::normalise('  Alex   Smith  ')==='Alex Smith','Whitespace matching');
expect(Lutful_Student_Schedule_Finder::normalise('alex Smith')!=='Alex Smith','Case remains significant');
expect(Lutful_Student_Schedule_Finder::selected_ids(array('1','2','1'))===array(1,2),'Deduplicate selected IDs');
expect(is_wp_error(Lutful_Student_Schedule_Finder::selected_ids(array('0'))),'Reject zero ID');
expect(is_wp_error(Lutful_Student_Schedule_Finder::selected_ids(array('1 OR 1=1'))),'Reject nonnumeric selection');
expect(is_wp_error(Lutful_Student_Schedule_Finder::selected_ids(array(array('1')))),'Reject nested selection');
expect(is_wp_error(Lutful_Student_Schedule_Finder::selected_ids(array())),'Reject empty selection');
expect(is_wp_error(Lutful_Student_Schedule_Finder::bulk_changes(array('first_name'),array('first_name'=>'Same'))),'Prevent shared name changes');
expect(is_wp_error(Lutful_Student_Schedule_Finder::bulk_changes(array(),array())),'Reject no bulk fields');
expect(Lutful_Student_Schedule_Finder::bulk_changes(array('details'),array('details'=>''))===array('details'=>''),'Explicit field clearing');
if(function_exists('iconv')) {
    $file=tempnam(sys_get_temp_dir(),'lssf');
    $text=implode("\t",$header)."\n".implode("\t",$student)."\n";
    file_put_contents($file,"\xFF\xFE".iconv('UTF-8','UTF-16LE',$text));
    $result=Lutful_Student_Schedule_Finder::parse_csv($file);unlink($file);
    expect(!is_wp_error($result) && count($result['records'])===1,'UTF-16 Excel text export');
}
if(class_exists('ZipArchive') && function_exists('simplexml_load_string')) {
    $file=tempnam(sys_get_temp_dir(),'lssf');$zip=new ZipArchive();$zip->open($file,ZipArchive::OVERWRITE);
    $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $zip->addFromString('xl/workbook.xml','<workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml','<styleSheet xmlns="'.$ns.'"><numFmts><numFmt numFmtId="164" formatCode="yyyy-mm-dd hh:mm:ss"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
    $xml='<worksheet xmlns="'.$ns.'"><sheetData>';
    foreach(array($header,$student) as $i=>$values) {
        $xml.='<row r="'.($i+1).'">';
        foreach($values as $column=>$value) {
            $ref=chr(65+$column).($i+1);
            if($i===1 && $column===3) { $xml.='<c r="'.$ref.'" s="1"><v>49311.666666666664</v></c>'; }
            else { $xml.='<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1,'UTF-8').'</t></is></c>'; }
        }
        $xml.='</row>';
    }
    $xml.='<row r="5000"><c r="G5000"/></row></sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();
    $result=Lutful_Student_Schedule_Import_Reader::xlsx($file);unlink($file);
    expect(!is_wp_error($result) && !$result['errors'],'Native XLSX parser');
    expect($result['records'][0]['rehearsal']==='2035-01-02 16:00:00','Excel date display without timezone conversion');
} else { throw new Exception('Enable ZIP and SimpleXML to run the complete regression suite.'); }
echo "PASS: CSV delimiters, Unicode encoding, blank rows, invalid records, exact names and native XLSX dates.\n";
