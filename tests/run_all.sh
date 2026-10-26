#!/bin/sh
# Requer MySQL/MariaDB com banco bf_test e usuário bf/bf (veja README). Roda todos os testes automatizados.
cd "$(dirname "$0")/.." || exit 1
for f in t_scheduling t_concurrency t_billing t_business t_integrations t_http; do php tests/$f.php || exit 1; done
