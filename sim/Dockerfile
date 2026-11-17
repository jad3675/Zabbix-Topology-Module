FROM python:3.12-slim
RUN pip install --no-cache-dir snmpsim==1.2.2 pysmi==2.0.0 && useradd -r -m sim
USER sim
EXPOSE 1161/udp
CMD ["snmpsim-command-responder", "--data-dir=/data", "--cache-dir=/tmp/snmpsim-cache", "--agent-udpv4-endpoint=0.0.0.0:1161"]
