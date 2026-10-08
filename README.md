# Um dia de cada vez

Aplicação monolítica local para acompanhamento pessoal de recuperação. O mesmo repositório contém backend PHP, frontend em HTML/CSS/JavaScript puro e banco MySQL.

## Requisitos

- Docker Desktop com Docker Compose

PHP e MySQL rodam em containers. O frontend é servido pelo PHP. Não é necessário instalar Node.js, npm, PHP ou Composer.

## Iniciar tudo com um comando (PowerShell)

Na raiz do projeto:

```powershell
powershell -ExecutionPolicy Bypass -File .\iniciar.ps1
```

O script compila e inicia a aplicação e o MySQL, aguarda os serviços e abre `http://localhost:5173`. Use `-SemNavegador` para não abrir o navegador.

Para encerrar os containers:

```powershell
docker compose down
```

## Iniciar manualmente

```powershell
docker compose up -d --build mysql backend
```

- Aplicação (frontend e API): `http://localhost:5173`
- API: `http://localhost:5173/api`
- MySQL: `localhost:3307`

## Funcionalidades iniciais

- Conta local com senha armazenada por hash e sessão em cookie HttpOnly.
- Perfil com nome, data de início do acompanhamento, histórico de tratamento e relato pessoal.
- Calendário mensal navegável, registro diário, emoções, relatos e acontecimentos.
- Histórico “Deu ruim” com indicação dos períodos no calendário.
- Inventário pessoal e fechamento do dia atual.
- Lembrete contextual às 22h para priorizar um local seguro.

Os dados de acompanhamento são sensíveis. Mantenha o banco na máquina local e proteja as credenciais. Esta primeira versão ainda não criptografa os campos armazenados no MySQL.

## Conteúdo de apoio

A pasta [`conteudos`](conteudos/README.md) guarda conteúdo local para revisão e futura integração. As mensagens iniciais são originais, não citações oficiais de NA/AA. Adicione apenas material autoral, paráfrases ou conteúdo cujo uso seja permitido. O aplicativo não consulta conteúdo online.
