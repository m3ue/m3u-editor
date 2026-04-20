FROM sparkison/m3u-editor:latest

# Porta padrão do m3u-editor
EXPOSE 36400

# Health check
HEALTHCHECK --interval=30s --timeout=10s --retries=5 --start-period=60s \
  CMD curl -f http://localhost:36400/up || exit 1
