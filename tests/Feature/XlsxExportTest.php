<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XlsxExportTest extends TestCase
{
    use RefreshDatabase;
    public function test_bookings_export_is_a_valid_xlsx_container():void
    {
        if(!class_exists(\ZipArchive::class)||!class_exists(\XMLWriter::class))$this->markTestSkipped('zip/xmlwriter extensions are unavailable');
        $admin=User::create(['name'=>'Export admin','email'=>'export@example.test','password'=>'long-password-123','is_admin'=>true]);
        $token=$admin->createToken('admin',['admin-panel'])->plainTextToken;
        foreach(['bookings','payments','refunds','settlements','counselor-earnings','reception-performance','room-utilization','capacity'] as $type){
            $response=$this->withToken($token)->get('/api/admin/booking/exports/'.$type.'.xlsx?from=2026-10-01&to=2026-10-31');
            $response->assertOk()->assertHeader('content-type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $path=$response->baseResponse->getFile()->getPathname();$zip=new \ZipArchive();
            $this->assertSame(true,$zip->open($path),$type.' export must be a valid ZIP workbook');$this->assertNotFalse($zip->getFromName('xl/worksheets/sheet1.xml'));$zip->close();
        }
    }
}
