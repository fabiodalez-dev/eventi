/*
 * Lighthouse CI — il criterio di accettazione di §11.11, reso bloccante.
 *
 * «Lighthouse >= 90 su Performance / SEO / Accessibility, LCP < 2s su mobile
 * simulato» era scritto nel piano e non veniva misurato da nessuno: una soglia
 * che nessuno controlla è una speranza. Qui diventa un lavoro della pipeline,
 * con le assertion al livello che lo fa fallire.
 *
 * I numeri sono quelli del piano, **non quelli che oggi passano**: D37 riporta
 * la misura reale — accessibilità e SEO passano ovunque, LCP no da nessuna
 * parte — e spiega perché il lavoro resta rosso invece di essere ammorbidito.
 *
 * **Il profilo predefinito di Lighthouse è già mobile simulato** (Moto G Power,
 * rete 4G lenta, CPU rallentata 4x): non va dichiarato, va lasciato stare. Chi
 * lo sostituisse con quello da scrivania per far passare le soglie starebbe
 * misurando un altro sito — ed è per questo che un test verifica che qui non
 * compaia alcun profilo dichiarato a mano.
 *
 * Tre indirizzi, uno per famiglia di pagina, perché hanno costi diversi:
 * la pagina iniziale monta le sezioni temporali, una lista pagina ventiquattro
 * card con le loro locandine, la scheda evento carica l'immagine grande sopra
 * la piega. Un solo indirizzo direbbe poco.
 *
 * `LHCI_EVENT_URL` arriva dalla pipeline, che lo pesca dal feed RSS del sito
 * appena avviato: lo slug di un evento nasce dal seeder e cambia a ogni
 * esecuzione, quindi scriverlo qui significherebbe misurare un 404.
 */
const base = (process.env.LHCI_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');

module.exports = {
    ci: {
        collect: {
            url: [
                `${base}/`,
                `${base}/eventi`,
                process.env.LHCI_EVENT_URL || `${base}/eventi/oggi`,
            ],

            /*
             * Tre giri per indirizzo: una sola misura su un runner condiviso
             * oscilla di parecchi punti, e un lavoro che fallisce a caso viene
             * disattivato dopo la seconda volta.
             */
            numberOfRuns: 3,

            settings: {
                /*
                 * `--no-sandbox` serve dentro un container; l'assenza di
                 * `--headless` non basta più, Chrome moderno lo vuole scritto.
                 */
                chromeFlags: '--no-sandbox --headless=new --disable-dev-shm-usage',

                /*
                 * PWA e best-practices non sono criteri di §11.11 e portano
                 * dentro controlli che non riguardano questo prodotto: si
                 * raccolgono solo le tre categorie su cui si giudica.
                 */
                onlyCategories: ['performance', 'accessibility', 'seo'],
            },
        },

        assert: {
            /*
             * Si giudica la **mediana** dei tre giri, non il migliore né il
             * peggiore: il migliore nasconderebbe una regressione, il peggiore
             * farebbe fallire per un singolo giro sfortunato del runner.
             */
            aggregationMethod: 'median',

            assertions: {
                'categories:performance': ['error', { minScore: 0.9 }],
                'categories:accessibility': ['error', { minScore: 0.9 }],
                'categories:seo': ['error', { minScore: 0.9 }],

                /*
                 * §11.11 in millisecondi. È la sola metrica dichiarata nel
                 * piano con un numero, ed è quella che l'utente sente.
                 */
                'largest-contentful-paint': ['error', { maxNumericValue: 2000 }],
            },
        },

        upload: {
            /*
             * I referti restano nel runner e vengono allegati all'esecuzione:
             * nessun server esterno, nessun account, nessun dato che esce.
             * La cartella sta fuori dal repository perché è un artefatto di
             * una misura, non del progetto.
             */
            target: 'filesystem',
            outputDir: process.env.LHCI_OUTPUT_DIR || './storage/lighthouse',
        },
    },
};
