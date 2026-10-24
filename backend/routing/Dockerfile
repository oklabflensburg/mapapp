# GraphHopper routing engine (see compose.yaml, `routing` profile).
# The jar is the GraphHopper library artifact with the embedded HTTP API;
# the app backend calls it at http://routing:8989 (GRAPHHOPPER_BASE_URL).
FROM eclipse-temurin:21-jre

ARG GRAPHHOPPER_VERSION=11.1

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir /app \
    && curl -fsSL -o /app/graphhopper.jar \
        "https://repo1.maven.org/maven2/com/graphhopper/graphhopper-web/${GRAPHHOPPER_VERSION}/graphhopper-web-${GRAPHHOPPER_VERSION}.jar"

COPY entrypoint.sh /entrypoint.sh
COPY graphhopper.yml /app/graphhopper.yml

EXPOSE 8989

ENTRYPOINT ["/entrypoint.sh"]
