<?php
declare(strict_types=1);

/** Tipos de negócio atendidos. Só muda rótulos e os serviços/equipe do "dados de exemplo"; as regras do sistema são as mesmas. */
final class Biz
{
    public const DEFAULT = 'barbearia';

    /** @return array<string,array{label:string,services:list<array>,team:list<string>,customers:list<string>}> */
    public static function types(): array
    {
        $m = ['Carlos', 'João', 'Pedro', 'Lucas', 'Mateus', 'Gabriel', 'Rafael', 'Bruno', 'Felipe', 'André', 'Thiago', 'Vinícius', 'Eduardo', 'Rodrigo', 'Leandro', 'Fábio', 'Gustavo', 'Henrique'];
        $f = ['Ana', 'Maria', 'Juliana', 'Camila', 'Fernanda', 'Patrícia', 'Aline', 'Bruna', 'Carla', 'Daniela', 'Eliane', 'Gabriela', 'Helena', 'Isabela', 'Joana', 'Larissa', 'Mônica', 'Renata'];
        return [
            'barbearia' => ['label' => 'Barbearia', 'customers' => $m, 'team' => ['Diego', 'Marcos'], 'services' => [
                ['Corte degradê', 45, 4500, 'Máquina e tesoura, acabamento na navalha'], ['Corte social', 40, 4000, 'Clássico, na tesoura ou máquina'], ['Corte navalhado', 50, 5000, 'Acabamento total na navalha'],
                ['Corte infantil', 30, 3500, 'Até 10 anos'], ['Barba', 30, 3000, 'Barba com toalha quente'], ['Corte + barba', 70, 7000, 'Combo completo'], ['Sobrancelha', 15, 1500, ''], ['Pigmentação', 40, 5000, ''], ['Hidratação', 30, 3000, '']]],
            'salao' => ['label' => 'Salão de beleza', 'customers' => $f, 'team' => ['Camila', 'Renata'], 'services' => [
                ['Corte feminino', 60, 8000, ''], ['Corte masculino', 30, 5000, ''], ['Escova', 45, 6000, 'Lavagem e escova modelada'], ['Coloração', 120, 18000, 'Inclui lavagem'], ['Mechas', 180, 35000, ''],
                ['Hidratação', 45, 9000, ''], ['Penteado', 60, 12000, 'Festas e eventos'], ['Progressiva', 150, 25000, '']]],
            'sobrancelha' => ['label' => 'Design de sobrancelha', 'customers' => $f, 'team' => ['Larissa', 'Bianca'], 'services' => [
                ['Design de sobrancelha', 40, 5000, ''], ['Design com henna', 50, 7000, ''], ['Brow lamination', 60, 15000, ''], ['Micropigmentação', 120, 35000, 'Fio a fio'],
                ['Epilação de buço', 15, 2000, ''], ['Cílios fio a fio', 120, 18000, '']]],
            'unhas' => ['label' => 'Manicure e unhas', 'customers' => $f, 'team' => ['Patrícia', 'Aline'], 'services' => [
                ['Manicure', 45, 4000, ''], ['Pedicure', 60, 5000, ''], ['Mão e pé', 105, 8000, ''], ['Esmaltação em gel', 60, 7000, ''],
                ['Alongamento em gel', 120, 15000, ''], ['Manutenção de alongamento', 90, 10000, ''], ['Remoção', 30, 4000, '']]],
            'estetica' => ['label' => 'Estética', 'customers' => $f, 'team' => ['Fernanda', 'Mariana'], 'services' => [
                ['Limpeza de pele', 60, 15000, ''], ['Massagem relaxante', 60, 13000, ''], ['Drenagem linfática', 60, 12000, ''], ['Depilação a cera', 30, 6000, ''], ['Extensão de cílios', 120, 18000, '']]],
            'outro' => ['label' => 'Outro tipo de negócio', 'customers' => array_merge($m, $f), 'team' => ['Profissional 2', 'Profissional 3'], 'services' => [
                ['Atendimento', 60, 8000, ''], ['Retorno', 30, 4000, '']]],
        ];
    }

    public static function normalize(?string $t): string
    {
        $t = (string)$t; return isset(self::types()[$t]) ? $t : self::DEFAULT;
    }
    public static function label(?string $t): string { return self::types()[self::normalize($t)]['label']; }
    /** @return array<string,string> chave => rótulo, para <select> */
    public static function options(): array { return array_map(fn($x) => $x['label'], self::types()); }
}
