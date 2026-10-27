<?php
declare(strict_types=1);
// Teste das regras PURAS da redistribuição (sem banco, sem servidor):   php tests/redistribution.test.php
date_default_timezone_set('America/Campo_Grande');
require __DIR__ . '/../app/Http.php';   // declara também a HttpError
require __DIR__ . '/../app/Redistribution.php';
use Rotina\Redistribution as R;
use Rotina\HttpError;

$fail = 0; $n = 0;
$eq = static function ($got, $want, string $msg) use (&$fail, &$n): void {
    $n++;
    if ($got !== $want) { $fail++; echo "FALHOU: $msg\n  obtido:   " . json_encode($got) . "\n  esperado: " . json_encode($want) . "\n"; }
};
$at = static fn(string $hm, string $day = '2026-10-21'): int => strtotime("$day $hm:00");

// ---- configuração: a rotina é sempre contínua (sem janela de abertura)
$eq(R::normalize(['on' => 1, 'when' => 'A', 'en' => ['MANHA']], true)['when'], 'C', 'modo antigo \"abertura\" vira contínuo');
$eq(R::defaults()['when'], 'C', 'padrão é contínuo');
$eq(R::normalize(['wait' => 45])['wait'], 45, 'espera mínima é respeitada');
$bad = false; try { R::normalize(['on' => 1, 'en' => []], true); } catch (HttpError $e) { $bad = true; }
$eq($bad, true, 'ligar sem turno habilitado é recusado');

// ---- escolha do técnico
$c = static fn(string $id, string $name, int $load, int $got = 0, string $shift = 'M') => ['id' => $id, 'name' => $name, 'shift' => $shift, 'load' => $load, 'got' => $got];
$cands = [$c('b', 'Bia', 4), $c('a', 'Ana', 2), $c('c', 'Caio', 2, 1)];
$eq(R::pick($cands, 15, 'L', null, null)['id'], 'a', 'menor carga; empate vai a quem recebeu menos hoje');
$eq(R::pick($cands, 15, 'L', 'a', null)['id'], 'c', 'menor carga sem o doador');
$eq(R::pick($cands, 15, 'L', 'a', null)['id'], 'c', 'não devolve ao mesmo técnico se há outro');
$eq(R::pick([$c('a', 'Ana', 2)], 15, 'L', 'a', null)['id'], 'a', 'devolve se for o único elegível');
$eq(R::pick([$c('a', 'Ana', 15), $c('b', 'Bia', 15)], 15, 'L', null, null), null, 'todos no teto: fica na fila central');
$eq(R::pick([$c('a', 'Ana', 14), $c('b', 'Bia', 15)], 15, 'L', null, null)['id'], 'a', 'quem está no teto não recebe');
$eq(R::pick([], 15, 'L', null, null), null, 'sem elegíveis');
// rodízio: ordem estável por id, continua do último
$seq = []; $rr = null;
for ($i = 0; $i < 7; $i++) { $t = R::pick($cands, 15, 'R', null, $rr); $seq[] = $t['id']; $rr = $t['id']; }
$eq($seq, ['a', 'b', 'c', 'a', 'b', 'c', 'a'], 'rodízio dá a volta');
$eq(R::pick($cands, 15, 'R', 'b', 'a')['id'], 'c', 'rodízio pula quem deixou a pendência');

// ---- equilíbrio entre turnos e desempate que gira
$sh = [$c('m1', 'Ana', 4, 0, 'MANHA'), $c('m2', 'Bia', 4, 0, 'MANHA'), $c('t1', 'Caio', 6, 0, 'TARDE')];
$eq(R::pick($sh, 99, 'L', null, null)['shift'], 'MANHA', 'turno com menor carga média recebe primeiro');
$sh2 = [$c('m1', 'Ana', 2, 0, 'MANHA'), $c('m2', 'Bia', 2, 0, 'MANHA'), $c('t1', 'Caio', 8, 0, 'TARDE')];
$eq(R::pick($sh2, 99, 'L', null, null)['shift'], 'MANHA', 'não despeja tudo no turno já sobrecarregado');
$tie = [$c('a', 'Ana', 3), $c('b', 'Bia', 3), $c('c', 'Caio', 3)];
$eq([R::pick($tie, 99, 'L', null, null)['id'], R::pick($tie, 99, 'L', null, 'a')['id'], R::pick($tie, 99, 'L', null, 'b')['id'], R::pick($tie, 99, 'L', null, 'c')['id']],
    ['a', 'b', 'c', 'a'], 'empate total: o desempate gira, não favorece sempre o mesmo nome');
// 3 turnos com nº de técnicos diferente: 12 novas demandas saem proporcionais e ninguém fica sobrecarregado
$pool = ['m1' => $c('m1', 'Ana', 0, 0, 'MANHA'), 'm2' => $c('m2', 'Bia', 0, 0, 'MANHA'), 't1' => $c('t1', 'Caio', 0, 0, 'TARDE'), 'n1' => $c('n1', 'Davi', 0, 0, 'NOITE')];
$rr = null; $got = ['MANHA' => 0, 'TARDE' => 0, 'NOITE' => 0];
for ($i = 0; $i < 12; $i++) { $t = R::pick($pool, 99, 'L', null, $rr); $pool[$t['id']]['load']++; $pool[$t['id']]['got']++; $rr = $t['id']; $got[$t['shift']]++; }
$eq($got, ['MANHA' => 6, 'TARDE' => 3, 'NOITE' => 3], 'turnos recebem proporcional ao nº de técnicos');
$eq(array_column($pool, 'load'), [3, 3, 3, 3], 'cada técnico termina com a mesma carga');

// ---- carga se equilibra ao distribuir 9 pendências por menor carga (filas 5, 0 e 2)
$cs = ['a' => $c('a', 'Ana', 5), 'b' => $c('b', 'Bia', 0), 'c' => $c('c', 'Caio', 2)];
for ($i = 0; $i < 9; $i++) { $t = R::pick($cs, 99, 'L', null, null); $cs[$t['id']]['load']++; $cs[$t['id']]['got']++; }
$loads = array_column($cs, 'load');
$eq([array_sum($loads), max($loads) - min($loads)], [16, 1], 'menor carga equilibra a fila (diferença de no máximo 1)');

// ---- validação da configuração
$d = R::defaults();
$eq($d['sh'], ['MANHA' => '06:00', 'TARDE' => '12:00', 'NOITE' => '18:00', 'MADRUGADA' => '00:00'], 'horários padrão');
$eq($d['on'], 0, 'desligada por padrão');
$eq(strlen(json_encode($d)) <= 255, true, 'cabe em app_state.v (255)');
$ok = R::normalize(['on' => 1, 'when' => 'C', 'how' => 'R', 'wait' => '45', 'sh' => ['TARDE' => '13:30'], 'en' => ['TARDE', 'XXX']], true);
$eq([$ok['when'], $ok['how'], $ok['wait'], $ok['sh']['TARDE'], $ok['en']], ['C', 'R', 45, '13:30', ['TARDE']], 'normaliza e filtra turnos inválidos');
foreach ([['sh' => ['MANHA' => '25:00']], ['sh' => ['MANHA' => '6:00']], ['cap' => 0], ['wait' => 'abc'], ['max' => 99], ['when' => 'Z'], ['on' => 1, 'en' => []]] as $i => $bad) {
    try { R::normalize($bad, true); $eq('aceitou', 'recusou', "entrada inválida #$i"); }
    catch (HttpError $e) { $eq($e->status, 400, "entrada inválida #$i vira 400"); }
}
$eq(R::normalize(['sh' => ['MANHA' => 'lixo'], 'cap' => -5])['cap'], 15, 'leitura do banco corrige em silêncio');

echo $fail ? "\n$fail de $n FALHARAM\n" : "OK: $n verificações passaram\n";
exit($fail ? 1 : 0);
