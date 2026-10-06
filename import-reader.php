<?php
if (!defined('ABSPATH')) { exit; }

/** Bounded native XLSX reader. No archive extraction and no external XML entities. */
class Lutful_Student_Schedule_Import_Reader {
    private static function xml($zip, $path) {
        $value = $zip->getFromName($path);
        if ($value === false || strlen($value)>8*1024*1024 || stripos($value,'<!DOCTYPE')!==false || stripos($value,'<!ENTITY')!==false) {
            throw new Exception('Missing, oversized or unsafe Excel XML: '.$path);
        }
        $before=libxml_use_internal_errors(true);
        $xml=simplexml_load_string($value,'SimpleXMLElement',LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($before);
        if ($xml===false) { throw new Exception('Invalid Excel XML.'); }
        return $xml;
    }
    private static function text($node) {
        $value=''; foreach ($node->xpath('.//*[local-name()="t"]') as $t) { $value.=(string)$t; } return $value;
    }
    private static function date_text($value,$format,$date1904) {
        $format=strtolower($format);
        $formats=array('yyyy-mm-dd hh:mm:ss'=>'Y-m-d H:i:s','dddd, mmmm d hh:mm'=>'l, F j H:i',
            'yyyy-mm-dd'=>'Y-m-d','m/d/yy'=>'n/j/y','mm-dd-yy'=>'m-d-y','m/d/yyyy'=>'n/j/Y',
            'd-mmm-yy'=>'j-M-y','d-mmm'=>'j-M','mmm-yy'=>'M-y','h:mm am/pm'=>'g:i A',
            'h:mm:ss am/pm'=>'g:i:s A','h:mm'=>'G:i','hh:mm'=>'H:i','h:mm:ss'=>'G:i:s','m/d/yy h:mm'=>'n/j/y G:i');
        if (!isset($formats[$format])) {
            // Numeric/general text stays numeric. Refuse unfamiliar date formats rather than change display silently.
            $plain=preg_replace('/"[^"]*"|\\\\./','',$format);
            if (preg_match('/[ydhs]|m{2,}/i',$plain)) { throw new Exception('Unsupported Excel date/time format "'.$format.'". Save as CSV with the desired displayed values.'); }
            return (string)$value;
        }
        $number=(float)$value;
        if ($number<0 || $number>2958465) { throw new Exception('Invalid Excel date value.'); }
        $days=(int)floor($number);
        $base=$date1904 ? '1904-01-01' : ($days<60 ? '1899-12-31' : '1899-12-30');
        $date=new DateTime($base,new DateTimeZone('UTC'));
        $date->modify('+'.$days.' days');
        $date->modify('+'.(int)round(($number-$days)*86400).' seconds');
        return $date->format($formats[$format]);
    }
    public static function xlsx($path) {
        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) { return new WP_Error('extensions','Excel import requires the PHP ZIP and SimpleXML extensions. Ask your host to enable them, or upload CSV.'); }
        $zip=new ZipArchive();
        if ($zip->open($path)!==true) { return new WP_Error('xlsx','Cannot open Excel workbook. Use an unencrypted .xlsx file.'); }
        try {
            $size=0;
            if ($zip->numFiles>2000) { throw new Exception('Excel file contains too many archive entries.'); }
            for ($i=0;$i<$zip->numFiles;$i++) { $stat=$zip->statIndex($i); $size+=$stat['size']; if ($size>20*1024*1024) { throw new Exception('Excel workbook expands beyond the 20 MB limit.'); } }
            $workbook=self::xml($zip,'xl/workbook.xml');
            $sheets=$workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
            if (!$sheets) { throw new Exception('Excel workbook has no worksheets.'); }
            $attrs=$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $id=(string)$attrs['id']; $sheetpath='';
            $rels=self::xml($zip,'xl/_rels/workbook.xml.rels');
            foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) {
                if ((string)$rel['Id']===$id && (string)$rel['TargetMode']!=='External') {
                    $target=(string)$rel['Target'];
                    if (substr($target,0,1)==='/') { $sheetpath=ltrim($target,'/'); }
                    else { $sheetpath='xl/'.$target; }
                }
            }
            if (!preg_match('~^xl/worksheets/[A-Za-z0-9_.-]+\.xml$~',$sheetpath)) { throw new Exception('Unsupported worksheet path. Resave the workbook in Excel.'); }
            $shared=array();
            if ($zip->locateName('xl/sharedStrings.xml')!==false) {
                $strings=self::xml($zip,'xl/sharedStrings.xml');
                foreach ($strings->xpath('//*[local-name()="si"]') as $s) { $shared[]=self::text($s); }
            }
            $formats=array(0=>'General',14=>'mm-dd-yy',15=>'d-mmm-yy',16=>'d-mmm',17=>'mmm-yy',18=>'h:mm am/pm',19=>'h:mm:ss am/pm',20=>'h:mm',21=>'h:mm:ss',22=>'m/d/yy h:mm'); $styles=array();
            if ($zip->locateName('xl/styles.xml')!==false) {
                $stylexml=self::xml($zip,'xl/styles.xml');
                foreach ($stylexml->xpath('//*[local-name()="numFmts"]/*[local-name()="numFmt"]') as $fmt) { $formats[(int)$fmt['numFmtId']]=(string)$fmt['formatCode']; }
                foreach ($stylexml->xpath('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $style) { $styles[]=(int)$style['numFmtId']; }
            }
            $properties=$workbook->xpath('//*[local-name()="workbookPr"]');
            $date1904=$properties && in_array((string)$properties[0]['date1904'],array('1','true'),true);
            $xml=self::xml($zip,$sheetpath); $rows=array();
            foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $values=array_fill(0,9,''); $nonempty=false;
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    $ref=(string)$cell['r'];
                    if (!preg_match('/^([A-Z]+)[0-9]+$/',$ref,$match)) { throw new Exception('Invalid Excel cell reference.'); }
                    $col=0; foreach (str_split($match[1]) as $letter) { $col=$col*26+ord($letter)-64; } $col--;
                    $type=(string)$cell['t']; $vs=$cell->xpath('./*[local-name()="v"]'); $value=$vs ? (string)$vs[0] : '';
                    if ($type==='inlineStr') { $value=self::text($cell); }
                    elseif ($type==='s') { if (!isset($shared[(int)$value])) { throw new Exception('Invalid shared Excel text.'); } $value=$shared[(int)$value]; }
                    elseif ($type==='e') { throw new Exception('Excel contains a formula error at '.$ref.'.'); }
                    elseif ($value!=='' && is_numeric($value) && ($type==='' || $type==='n')) {
                        $index=(int)$cell['s']; $formatid=isset($styles[$index]) ? $styles[$index] : 0;
                        if (isset($formats[$formatid])) { $value=self::date_text($value,$formats[$formatid],$date1904); }
                    }
                    if ($cell->xpath('./*[local-name()="f"]') && !$vs) { throw new Exception('Formula has no saved result at '.$ref.'. Recalculate and save Excel first.'); }
                    if (trim($value)!=='') { $nonempty=true; if ($col>8) { throw new Exception('Excel has data outside the nine required columns.'); } }
                    if ($col<9) { $values[$col]=$value; }
                }
                if ($nonempty) { $rows[]=$values; }
                if (count($rows)>Lutful_Student_Schedule_Finder::MAX_ROWS+1) { throw new Exception('Excel exceeds 1,000 student rows.'); }
            }
            $zip->close(); return Lutful_Student_Schedule_Finder::parse_rows($rows);
        } catch (Exception $error) { $zip->close(); return new WP_Error('xlsx',$error->getMessage()); }
    }
}
