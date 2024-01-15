/**
 * @memberof cy
 * @method login
 * @description Login to GLPI. This command will also reuse the session for subsequent calls when possible rather than logging in again.
 * @param {string} username - Username
 * @param {string} password - Password
 * @returns Chainable
 */

/**
 * @memberof cy
 * @method waitForInputs
 * @description Wait for libraries to replace inputs with their own components
 * @returns Chainable
 */

/**
 * @memberof cy
 * @method selectDate
 * @description Select a date in a flatpickr input
 * @param {string} date - Date to select
 * @param {boolean} interactive - Whether to use the flatpickr calendar or type the date
 * @returns Chainable
 */


Cypress.Commands.add('login', (username, password) => {
    cy.session(
        username,
        () => {
            cy.visit('/');
            cy.title().should('eq', 'Authentication - GLPI');
            cy.get('#login_name').type(username);
            cy.get('input[type="password"]').type(password);
            cy.get('#login_remember').check();
            // Select 'local' from the 'auth' dropdown
            cy.get('select[name="auth"]').select('local', { force: true });

            cy.get('button[type="submit"]').click();
            cy.url().should('include', '/front/central.php');
        },
        {
            validate: () => {
                cy.getCookies().should('have.length.gte', 2).then((cookies) => {
                    // Should be two cookies starting with 'glpi_' and one of them should end with '_rememberme'
                    expect(cookies.filter((cookie) => cookie.name.startsWith('glpi_'))).to.have.length(2);
                    expect(cookies.filter((cookie) => cookie.name.startsWith('glpi_') && cookie.name.endsWith('_rememberme'))).to.have.length(1);
                });
            },
        }
    );
});

/**
 * Wait for libraries to replace inputs with their own components
 */
Cypress.Commands.add('waitForInputs', () => {
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(500);
});

Cypress.Commands.overwrite('type', (originalFn, subject, text, options) => {
    // If the subject is a textarea, see if there is a TinyMCE editor for it
    // If there is, set the content of the editor instead of the textarea
    if (subject.is('textarea')) {
        cy.get(`textarea[name="${subject.attr('name')}"]`).invoke('attr', 'id').then((textarea_id) => {
            cy.window().then((win) => {
                if (win.tinymce.get(textarea_id)) {
                    win.tinymce.get(textarea_id).setContent(text);
                    return;
                }
                originalFn(subject, text, options);
            });
        });
        return;
    }
    return originalFn(subject, text, options);
});

Cypress.Commands.add('selectDate', {
    prevSubject: 'element',
}, (subject, date, interactive = true) => {
    // the subject should exist
    cy.wrap(subject).should('exist').then((subject) => {
        cy.wrap(subject).should('satisfy', (subject) => {
            return subject.attr('type') === 'hidden' && subject.hasClass('flatpickr-input');
        }).then((subject) => {
            if (subject.attr('type') === 'hidden' && subject.hasClass('flatpickr-input')) {
                if (interactive) {
                    cy.wrap(subject).parents('.flatpickr').find('input:not(.flatpickr-input)').click();
                    // Parse the date to get the desired year, month and day
                    const date_obj = new Date(date);
                    const year = date_obj.getFullYear();
                    const month = date_obj.getMonth();
                    const day = date_obj.getDate();

                    cy.get('.flatpickr-calendar.open').within(() => {
                        cy.get('.flatpickr-monthDropdown-months').select(month);
                        cy.get('input.cur-year').clear();
                        cy.get('input.cur-year').type(year);
                        cy.get('.flatpickr-day').contains(new RegExp(`^${day}$`)).click();
                    });
                } else {
                    cy.wrap(subject).parents('.flatpickr').find('input:not(.flatpickr-input)').type(date);
                }
            }
        });
    });
});

