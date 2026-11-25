<?php

namespace App\Services\Statements;

use App\Models\Statement;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF на известието с dompdf (PROJECT.md, „PDF и имейл на известията“,
 * 24.11.2026). Прави се при поискване от запомнените при издаването данни;
 * файлът не се пази. Шрифтът е DejaVu Sans (идва с dompdf, с кирилица).
 * Без външни ресурси и без PHP в шаблона.
 */
final class StatementPdf
{
    public function html(Statement $statement): string
    {
        $statement->loadMissing(['leaseContract', 'lines.property', 'lines.paidUtilityBill', 'lines.utilityAccount.provider']);

        return view('statements.pdf', ['statement' => $statement, 'logo' => $this->logo($statement)])->render();
    }

    /** Текущото лого на фирмата на известието (не запомненото при издаването). */
    public function logo(Statement $statement): ?string
    {
        return \App\Models\Company::query()->find($statement->company_id)?->logoDataUri();
    }

    public function pdf(Statement $statement): string
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setTempDir(sys_get_temp_dir());
        $options->setChroot([resource_path()]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($statement), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function filename(Statement $statement): string
    {
        return 'izvestie-'.$statement->number.'.pdf';
    }
}
