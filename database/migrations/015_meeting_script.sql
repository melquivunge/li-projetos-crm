-- 015: roteiro da reunião inicial de 30 min (documento "Estratégia de Posicionamento", seção 15).
-- É um briefing da equipe (kind = 'reuniao'): preenchido durante a conversa, nunca enviado ao cliente.
ALTER TABLE briefings ADD COLUMN kind ENUM('servico','reuniao') NOT NULL DEFAULT 'servico' AFTER service;
INSERT IGNORE INTO briefing_templates (service, questions) VALUES ('Reunião inicial', 'Faça uma pergunta por vez e use o que o cliente contou para aprofundar: “Falta espaço” → “Em qual situação isso acontece?” → “O que vocês precisam guardar ou fazer ali?” Não é necessário fazer todas as perguntas, nem prometer desenvolver o projeto nesta conversa. | roteiro
Abertura — minutos 0 a 3 | titulo
“Quero entender como vocês vivem, o que precisam resolver no imóvel e o investimento que pretendem fazer. Depois explico qual serviço faz sentido, o que inclui e como chegamos ao valor. Se não for o momento ou não couber no planejamento, podemos conversar com tranquilidade.” | roteiro
Imóvel e momento — minutos 3 a 8 | titulo
B01 — É apartamento, casa existente ou terreno? | select | Apartamento;Casa existente;Terreno;Comercial
B01 — Em qual cidade fica? | text
B02 — Área aproximada (m²) | number
B02 — Quais ambientes precisam entrar no projeto? | textarea
B03 — Vocês já têm as chaves, planta, medidas ou outros documentos do imóvel? | textarea
B04 — A obra já começou? Existe algum profissional contratado ou decisão já tomada? | textarea
B05 — Há uma data desejada para mudança ou início da obra? O que determina essa data? | textarea
Rotina e prioridades — minutos 8 a 15 | titulo
B06 — Quem vai morar ou usar o espaço e quais necessidades precisam ser consideradas? | textarea
B07 — O que mais incomoda vocês no espaço atual ou na planta recebida? | textarea
B08 — Como vocês usam a casa durante a semana e quando recebem pessoas? | textarea
B09 — Que atividades precisam de espaço: trabalho, descanso, armazenamento, convivência ou outras? | textarea
B10 — O que precisa ser preservado, como móveis, equipamentos ou acabamentos? | textarea
B11 — Quais três prioridades não podem ficar de fora? | textarea
B12 — O que pode ser feito depois, se precisarmos dividir em fases? | textarea
B13 — Quais referências vocês gostam e o que gostam nelas: luz, materiais, organização ou sensação? | textarea
B14 — O que vocês não gostariam de ver no resultado? | textarea
Segurança na decisão e investimento — minutos 15 a 23 | titulo
B15 — Qual é a principal preocupação? | select | Resultado;Custos;Prazo;Escolhas;Execução
B16 — Já contrataram um projeto antes? O que funcionou e o que gostariam de fazer diferente? | textarea
B17 — Quem participa das decisões e quem precisa estar na apresentação da proposta? | textarea
B18 — Existe um valor total planejado para a transformação do imóvel? O que esse valor inclui? | textarea
B19 — Já separaram os honorários do projeto dos custos de obra, móveis e materiais? | textarea
B20 — Qual faixa de investimento em projeto seria confortável avaliar? (ou preferem conhecer os escopos primeiro) | textarea
B21 — Se o escopo completo ultrapassar o planejamento, preferem: | select | Reduzir ambientes;Dividir em etapas;Adiar;Ainda não sabem
B22 — O que precisam entender para se sentirem seguros em contratar? | textarea
Pergunte sobre orçamento para verificar compatibilidade e recomendar escopo, sem pressionar pela divulgação de um teto. Não ajuste o preço ao máximo que a pessoa diz poder pagar. | roteiro
Síntese e próximo passo — minutos 23 a 30 | titulo
B23 — “Pelo que conversamos, as prioridades são [A], [B] e [C]. Está correto?” | textarea
B24 — Ficou alguma preocupação importante que ainda não discutimos? | textarea
“Minha recomendação é [serviço], porque vocês precisam de [necessidade]. Ele inclui [entregas] e não inclui [limites]. Para confirmar a proposta, preciso de [informação]. Envio até [data] e podemos revisar juntos em [data].” | roteiro
Registro após a conversa | titulo
Imóvel (resumo) | textarea
Prioridades | textarea
Participantes | text
Escopo sugerido | select | Projetos de Arquitetura;Design de Interiores;Plantas Baixas;Consultoria;Detalhamento Executivo;Ainda não definido
Orçamento | select | Informado;Não informado
Valor ou faixa informada | text
Prazo | text
Preocupação principal | text
Informações pendentes | textarea
Responsável | text
Próxima ação e data | text');
