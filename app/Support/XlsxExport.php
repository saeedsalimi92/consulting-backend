<?php
namespace App\Support;

class XlsxExport
{
    public static function download(string $filename,array $headers,iterable $rows)
    {
        abort_unless(class_exists(\ZipArchive::class)&&class_exists(\XMLWriter::class),503,'XLSX requires PHP zip and xmlwriter extensions.');
        $directory=sys_get_temp_dir();$sheet=tempnam($directory,'xlsx_sheet_');$file=tempnam($directory,'xlsx_file_');
        $xml=new \XMLWriter();$xml->openUri($sheet);$xml->startDocument('1.0','UTF-8');$xml->startElement('worksheet');$xml->writeAttribute('xmlns','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$xml->startElement('sheetData');
        $writeRow=function(int $index,array $values)use($xml){$xml->startElement('row');$xml->writeAttribute('r',(string)$index);foreach(array_values($values) as $column=>$value){$ref=self::column($column+1).$index;$xml->startElement('c');$xml->writeAttribute('r',$ref);
            if(is_int($value)||is_float($value)){$xml->writeElement('v',(string)$value);}elseif($value===null){ }else{$xml->writeAttribute('t','inlineStr');$xml->startElement('is');$xml->startElement('t');$xml->writeAttribute('xml:space','preserve');$xml->text((string)$value);$xml->endElement();$xml->endElement();}$xml->endElement();}$xml->endElement();};
        $writeRow(1,$headers);$index=2;foreach($rows as $row){abort_if($index>1048576,422,'XLSX worksheet row limit exceeded; narrow the date range.');$writeRow($index++,(array)$row);}$xml->endElement();$xml->endElement();$xml->endDocument();$xml->flush();
        $zip=new \ZipArchive();if($zip->open($file,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true){@unlink($sheet);@unlink($file);abort(500,'Cannot create XLSX archive.');}
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFile($sheet,'xl/worksheets/sheet1.xml');$zip->close();@unlink($sheet);
        $safe=preg_replace('/[^A-Za-z0-9._-]/','-',basename($filename));
        return response()->download($file,$safe,['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
    private static function column(int $number):string{$name='';while($number>0){$number--; $name=chr(65+$number%26).$name;$number=intdiv($number,26);}return $name;}
}
