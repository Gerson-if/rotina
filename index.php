<?php
// Se o servidor web aponta para a PASTA DO PROJETO (e não para public/), leva o visitante para public/.
// O recomendado é apontar o Apache/Nginx para a pasta public/ (veja deploy/).
header('Location: public/');
