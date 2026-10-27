<?php
declare(strict_types=1);
namespace Rotina;

/**
 * Divide texto SQL em comandos, respeitando aspas, crases e comentários.
 * Funciona em fluxo (feed() por pedaços), então restaura dumps grandes sem carregar tudo na memória.
 * Aceita dumps do phpMyAdmin (comentários condicionais do MySQL, que começam com barra-asterisco-exclamação, passam direto ao servidor).
 * Não suporta DELIMITER (o sistema não usa triggers/procedures).
 */
final class SqlSplitter
{
    private string $buf = '';
    private ?string $q = null;     // aspa aberta: ' " `
    private bool $block = false;   // dentro de /* ... */
    private bool $line = false;    // dentro de -- ou #
    private bool $esc = false;     // \ no fim do pedaço anterior
    private string $carry = '';    // caractere ambíguo no fim do pedaço (-, /, *) que depende do próximo pedaço

    /** @return string[] comandos completos encontrados neste pedaço */
    public function feed(string $piece): array { return $this->run($piece, false); }

    private function run(string $piece, bool $final): array
    {
        $out = [];
        $piece = $this->carry . $piece;
        $this->carry = '';
        $n = strlen($piece);
        $i = 0;
        while ($i < $n) {
            if ($this->line) {
                $nl = strpos($piece, "\n", $i);
                if ($nl === false) { $i = $n; break; }
                $i = $nl + 1; $this->line = false; $this->buf .= "\n";
                continue;
            }
            if ($this->block) {
                $e = strpos($piece, '*/', $i);
                if ($e === false) {
                    if (!$final && $n > 0 && $piece[$n - 1] === '*') $this->carry = '*';
                    $i = $n; break;
                }
                $i = $e + 2; $this->block = false;
                continue;
            }
            if ($this->q !== null) {
                if ($this->esc) { $this->buf .= $piece[$i]; $i++; $this->esc = false; continue; }
                $stop = $this->q === '`' ? '`' : $this->q . '\\';
                $len = strcspn($piece, $stop, $i);
                $this->buf .= substr($piece, $i, $len);
                $i += $len;
                if ($i >= $n) break;
                $c = $piece[$i];
                $this->buf .= $c; $i++;
                if ($c === '\\') $this->esc = true; else $this->q = null;
                continue;
            }
            $len = strcspn($piece, '\'"`-#/;', $i);
            $this->buf .= substr($piece, $i, $len);
            $i += $len;
            if ($i >= $n) break;
            $c = $piece[$i];
            if ($c === "'" || $c === '"' || $c === '`') { $this->q = $c; $this->buf .= $c; $i++; }
            elseif ($c === '-') {
                if (!$final && ($i + 1 >= $n || ($piece[$i + 1] === '-' && $i + 2 >= $n))) {
                    $this->carry = substr($piece, $i); break;           // "-" ou "--" no fim: decide com o próximo pedaço
                }
                if (($piece[$i + 1] ?? '') === '-' && ($i + 2 >= $n || ctype_space($piece[$i + 2]))) { $this->line = true; $i += 2; }
                else { $this->buf .= '-'; $i++; }
            }
            elseif ($c === '#') { $this->line = true; $i++; }
            elseif ($c === '/') {
                if (!$final && ($i + 1 >= $n || ($piece[$i + 1] === '*' && $i + 2 >= $n))) {
                    $this->carry = substr($piece, $i); break;           // "/" ou "/*" no fim: decide com o próximo pedaço
                }
                if (($piece[$i + 1] ?? '') === '*' && ($piece[$i + 2] ?? '') !== '!') { $this->block = true; $i += 2; }
                else { $this->buf .= '/'; $i++; }
            }
            else { // ';'
                $stmt = trim($this->buf); $this->buf = ''; $i++;
                if ($stmt !== '') $out[] = $stmt;
            }
        }
        return $out;
    }

    /** Comando final sem ';' (se houver) */
    public function finish(): array
    {
        $this->run('', true);                      // resolve um eventual caractere ambíguo pendente
        $stmt = trim($this->buf);
        $this->buf = '';
        return $stmt === '' ? [] : [$stmt];
    }

    public static function split(string $sql): array
    {
        $s = new self();
        return array_merge($s->feed($sql), $s->finish());
    }
}
