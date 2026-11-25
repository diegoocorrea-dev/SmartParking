<?php
/**
 * vagas_dados.php - vagas, carregadores e tarifa vindos do banco smartparking
 * (tabelas vagas, pisos, carregadores, tarifas, configuracoes).
 * Se o banco estiver fora do ar, a home continua abrindo com dados de exemplo.
 */
require_once __DIR__ . '/init.php';

// Valores de reserva, usados quando o banco não responde ou a chave não existe
const CONFIG_PADRAO = [
    'preco_hora' => 6.0,
    'preco_kwh' => 1.5,
    'bateria_kwh' => 60.0,   // configuracoes.bateria_padrao_kwh (opcional)
    'potencia_padrao_kw' => 22.0,   // configuracoes.potencia_padrao_kw (opcional)
];

const SQL_VAGAS = 'SELECT v.id, p.nome AS piso, v.codigo, v.tipo AS categoria, v.status,
                          c.conector, c.potencia_kw
                     FROM vagas v
                     JOIN pisos p ON p.id = v.piso_id
                     LEFT JOIN carregadores c ON c.vaga_id = v.id';

function config_preco(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = CONFIG_PADRAO;
    $pdo = db_tentar();
    if ($pdo) {
        try {
            // tarifa ativa (a mais recente)
            $t = $pdo->query('SELECT preco_hora, preco_kwh FROM tarifas WHERE ativa = TRUE ORDER BY id DESC LIMIT 1')->fetch();
            if ($t) {
                $cfg['preco_hora'] = (float) $t['preco_hora'];
                $cfg['preco_kwh'] = (float) $t['preco_kwh'];
            }
            // ajustes opcionais
            $mapa = ['bateria_padrao_kwh' => 'bateria_kwh', 'potencia_padrao_kw' => 'potencia_padrao_kw'];
            foreach ($pdo->query('SELECT chave, valor FROM configuracoes') as $l) {
                if (isset($mapa[$l['chave']]) && is_numeric($l['valor'])) {
                    $cfg[$mapa[$l['chavfe']]] = (float) $l['valor'];
                }
            }
        } catch (PDOException $ex) {
            error_log('SmartParking - tarifas/configuracoes: ' . $ex->getMessage());
        }
    }
    return $cfg;
}

function normalizar_vaga(array $v): array
{
    $v['id'] = (int) $v['id'];
    $v['potencia_kw'] = $v['potencia_kw'] !== null ? (float) $v['potencia_kw'] : null;
    $v['tem_carregador'] = $v['categoria'] === 'eletrica';
    return $v;
}

function carregar_vagas(): array
{
    $pdo = db_tentar();
    if ($pdo) {
        try {
            $sql = SQL_VAGAS . ' ORDER BY p.ordem, v.codigo';
            return array_map('normalizar_vaga', $pdo->query($sql)->fetchAll());
        } catch (PDOException $ex) {
            error_log('SmartParking - vagas: ' . $ex->getMessage());
        }
    }
    return vagas_exemplo();
}

function buscar_vaga(int $id): ?array
{
    $pdo = db_tentar();
    if (!$pdo) {
        return null;
    }
    try {
        $st = $pdo->prepare(SQL_VAGAS . ' WHERE v.id = :id');
        $st->execute([':id' => $id]);
        $v = $st->fetch();
        return $v ? normalizar_vaga($v) : null;
    } catch (PDOException $ex) {
        error_log('SmartParking - buscar_vaga: ' . $ex->getMessage());
        return null;
    }
}

/** Contagem por status e de carregadores. */
function contar_vagas(array $vagas): array
{
    $c = ['livre' => 0, 'ocupada' => 0, 'manutencao' => 0, 'carregadores' => 0];
    foreach ($vagas as $v) {
        $c[$v['status']]++;
        if ($v['tem_carregador']) {
            $c['carregadores']++;
        }
    }
    return $c;
}

function rotulo_status(string $status): string
{
    return ['livre' => 'Livre', 'ocupada' => 'Ocupada', 'manutencao' => 'Manutenção'][$status] ?? $status;
}

function rotulo_categoria(string $categoria): string
{
    return ['comum' => 'Comum', 'eletrica' => 'Elétrica', 'pcd' => 'PcD', 'idoso' => 'Idoso', 'moto' => 'Moto'][$categoria] ?? $categoria;
}

function rotulo_conector(?string $conector): string
{
    return $conector === 'Tipo2' ? 'Tipo 2' : (string) $conector;
}

/** 22.0 -> "22", 7.4 -> "7,4" */
function fmt_kw($kw): string
{
    return $kw === null ? '' : rtrim(rtrim(number_format((float) $kw, 1, ',', ''), '0'), ',');
}

function brl(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

/** Total da estadia (e da carga, se $com_carga) - mesma conta do vagas.js. */
function total_estadia(int $horas, array $cfg, bool $com_carga): float
{
    $total = $cfg['preco_hora'] * $horas;
    if ($com_carga) {
        $total += min($cfg['potencia_padrao_kw'] * $horas, $cfg['bateria_kwh']) * $cfg['preco_kwh'];
    }
    return $total;
}

/** Dados de exemplo (só usados se o banco não responder). */
function vagas_exemplo(): array
{
    $linhas = [
        ['Térreo', 'A01', 'comum', 'livre'],
        ['Térreo', 'A02', 'comum', 'ocupada'],
        ['Térreo', 'A03', 'comum', 'livre'],
        ['Térreo', 'A04', 'comum', 'livre'],
        ['Térreo', 'A05', 'comum', 'ocupada'],
        ['Térreo', 'A06', 'eletrica', 'livre', 'CCS2', 50],
        ['Térreo', 'B01', 'comum', 'livre'],
        ['Térreo', 'B02', 'comum', 'ocupada'],
        ['Térreo', 'B03', 'eletrica', 'livre', 'Tipo2', 22],
        ['Térreo', 'B04', 'comum', 'manutencao'],
        ['Térreo', 'B05', 'comum', 'ocupada'],
        ['Térreo', 'B06', 'eletrica', 'livre', 'Tipo2', 22],
    ];
    $vagas = [];
    foreach ($linhas as $i => $l) {
        $vagas[] = normalizar_vaga([
            'id' => $i + 1,
            'piso' => $l[0],
            'codigo' => $l[1],
            'categoria' => $l[2],
            'status' => $l[3],
            'conector' => $l[4] ?? null,
            'potencia_kw' => $l[5] ?? null,
        ]);
    }
    return $vagas;
}
