/**
 * Il segnaposto trascinabile dei moduli del pannello.
 *
 * **Perché una mappa e non due caselle.** Latitudine e longitudine si
 * scrivevano a mano: `45.4302`, `11.8583`. Nessuno le conosce, si copiano da
 * un'altra scheda o si lasciano quelle del centro città — ed è esattamente
 * quello che succedeva, con tutti i locali accatastati in piazza. Una mappa
 * risponde alla domanda vera, che non è «quali sono le coordinate» ma «dov'è
 * questo posto».
 *
 * **Leaflet arriva quando serve.** L'import è dinamico: chi apre l'elenco dei
 * locali o la scheda di un evento senza mappa non scarica 150 KB di libreria.
 * È la stessa scelta fatta per la mappa del sito pubblico.
 */

let leaflet = null;

async function caricaLeaflet() {
    if (leaflet) return leaflet;

    const modulo = await import('leaflet');
    leaflet = modulo.default ?? modulo;

    /*
     * Le icone predefinite di Leaflet puntano a file che il nostro bundle non
     * pubblica: il segnaposto sparisce e in console compare un 404. Qui se ne
     * disegna uno con il CSS, quindi si toglie di mezzo il meccanismo.
     */
    delete leaflet.Icon.Default.prototype._getIconUrl;

    return leaflet;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mappaSegnaposto', (config) => ({
        mappa: null,
        segnaposto: null,
        lat: config.lat,
        lng: config.lng,

        async init() {
            const L = await caricaLeaflet();

            /*
             * Senza coordinate si parte dal centro della città con uno zoom
             * ampio: dice «scegli tu», mentre un punto già piantato in centro
             * fa credere che la posizione sia stata decisa.
             */
            const haPosizione = this.lat !== null && this.lng !== null;
            const centro = haPosizione ? [this.lat, this.lng] : [config.fallbackLat, config.fallbackLng];

            this.mappa = L.map(this.$refs.mappa, {
                center: centro,
                zoom: haPosizione ? 16 : 13,
                scrollWheelZoom: false,
            });

            L.tileLayer(config.tiles, {
                attribution: config.attribution,
                maxZoom: 19,
            }).addTo(this.mappa);

            const icona = L.divIcon({
                className: 'mappa-segnaposto',
                html: '<span></span>',
                iconSize: [26, 26],
                iconAnchor: [13, 26],
            });

            this.segnaposto = L.marker(centro, { draggable: true, icon: icona, keyboard: true }).addTo(this.mappa);

            if (haPosizione) {
                this.segnaposto.setOpacity(1);
            }

            this.segnaposto.on('dragend', () => this.aggiorna(this.segnaposto.getLatLng()));

            /* Un clic sulla mappa sposta il segnaposto: trascinare è preciso
               ma richiede di trovarlo prima, e sulla prima apertura il
               segnaposto sta al centro dove nessuno lo cerca. */
            this.mappa.on('click', (e) => {
                this.segnaposto.setLatLng(e.latlng);
                this.aggiorna(e.latlng);
            });

            /* La mappa nasce dentro un modulo che può essere in una scheda
               nascosta: Leaflet misura il contenitore alla creazione e, se
               allora era invisibile, disegna un riquadro alto zero. */
            const osservatore = new ResizeObserver(() => this.mappa.invalidateSize());
            osservatore.observe(this.$refs.mappa);
        },

        aggiorna({ lat, lng }) {
            /* Sette decimali: è la precisione della colonna sul database, e
               scriverne di più significa salvare cifre che vengono troncate,
               con il campo che al ricaricamento mostra un valore diverso da
               quello appena scelto. */
            this.lat = Number(lat.toFixed(7));
            this.lng = Number(lng.toFixed(7));
            this.$wire.set(config.statePathLat, this.lat, false);
            this.$wire.set(config.statePathLng, this.lng, false);
        },

        /** Porta il segnaposto su un punto arrivato da fuori (la geocodifica). */
        vaiA(lat, lng) {
            if (!this.mappa) return;

            const punto = [lat, lng];
            this.segnaposto.setLatLng(punto);
            this.mappa.setView(punto, 17);
            this.aggiorna({ lat, lng });
        },
    }));
});
