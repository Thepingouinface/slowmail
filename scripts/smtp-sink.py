"""Faux serveur SMTP local, pour éprouver SlowMail sans rien envoyer pour de vrai.

Le comportement dépend du début de la partie locale du destinataire :
  bounce…     refuse l'adresse au RCPT TO (550 5.1.1)              -> rebond
  temp…       répond « plus tard » au RCPT TO (451)                -> échec temporaire, relance
  rejectdata… refuse le message après le « . » final (554)        -> échec définitif
  dropafter…  ferme la connexion après le « . » sans répondre     -> envoi incertain
  slow…       attend 3 s avant d'accepter (élargit la fenêtre de course)
  autre       accepte
Les messages acceptés sont notés (une ligne JSON) dans le fichier donné.

Usage :
    python3 scripts/smtp-sink.py 2525 /tmp/sink.jsonl
puis, dans le .env (ou en variables d'environnement) :
    MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=2525 MAIL_SCHEME=smtp MAIL_USERNAME= MAIL_PASSWORD=
Depuis un conteneur Docker : SINK_HOST=0.0.0.0 python3 scripts/smtp-sink.py 2525 /tmp/sink.jsonl
et MAIL_HOST=host.docker.internal.
"""

import asyncio
import json
import os
import re
import sys
import time

PORT = int(sys.argv[1])
OUT = sys.argv[2]
# 127.0.0.1 par défaut : jamais joignable depuis le réseau. Pour qu'un conteneur l'atteigne
# (MAIL_HOST=host.docker.internal), lancer avec SINK_HOST=0.0.0.0 le temps de l'essai.
HOST = os.environ.get("SINK_HOST", "127.0.0.1")


async def handle(reader, writer):
    async def send(line):
        writer.write((line + "\r\n").encode())
        await writer.drain()

    await send("220 sink.local ESMTP")
    rcpt = ""

    while True:
        raw = await reader.readline()
        if not raw:
            break
        cmd = raw.decode(errors="replace").rstrip("\r\n")
        up = cmd.upper()

        if up.startswith("EHLO"):
            await send("250-sink.local")
            await send("250 8BITMIME")
        elif up.startswith("HELO"):
            await send("250 sink.local")
        elif up.startswith("MAIL FROM"):
            await send("250 2.1.0 ok")
        elif up.startswith("RCPT TO"):
            m = re.search(r"<([^>]*)>", cmd)
            rcpt = m.group(1) if m else ""
            local = rcpt.split("@")[0]
            if local.startswith("bounce"):
                await send(f"550 5.1.1 <{rcpt}>: Recipient address rejected: User unknown")
                continue
            if local.startswith("temp"):
                await send("451 4.7.1 Greylisted, please try again later")
                continue
            await send("250 2.1.5 ok")
        elif up == "DATA":
            await send("354 go ahead")
            data = b""
            while True:
                line = await reader.readline()
                if not line:
                    return
                if line in (b".\r\n", b".\n"):
                    break
                data += line
            local = rcpt.split("@")[0]
            if local.startswith("dropafter"):
                writer.close()  # le « . » est arrivé, aucune réponse : le client ne saura jamais
                return
            if local.startswith("rejectdata"):
                await send("554 5.7.1 Message rejected")
                continue
            if local.startswith("slow"):
                await asyncio.sleep(3)
            unfolded = re.sub(rb"\r\n[ \t]+", b" ", data)
            mid = re.search(rb"(?im)^Message-ID:\s*<([^>]+)>", unfolded)
            with open(OUT, "a") as f:
                f.write(json.dumps({"rcpt": rcpt, "message_id": mid.group(1).decode() if mid else None, "t": time.time(), "bytes": len(data)}) + "\n")
            await send("250 2.0.0 queued")
        elif up.startswith("QUIT"):
            await send("221 bye")
            break
        else:
            await send("250 ok")

    writer.close()


async def main():
    server = await asyncio.start_server(handle, HOST, PORT)
    async with server:
        await server.serve_forever()


asyncio.run(main())
