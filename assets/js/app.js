const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const mobileMenuButton = document.getElementById('mobileMenuButton');
const sidebarClose = document.getElementById('sidebarClose');


function openSidebar() {

    if (!sidebar || !sidebarOverlay) {
        return;
    }

    sidebar.classList.add('open');
    sidebarOverlay.classList.add('active');
}


function closeSidebar() {

    if (!sidebar || !sidebarOverlay) {
        return;
    }

    sidebar.classList.remove('open');
    sidebarOverlay.classList.remove('active');
}


if (mobileMenuButton) {

    mobileMenuButton.addEventListener(
        'click',
        openSidebar
    );
}


if (sidebarClose) {

    sidebarClose.addEventListener(
        'click',
        closeSidebar
    );
}


if (sidebarOverlay) {

    sidebarOverlay.addEventListener(
        'click',
        closeSidebar
    );
}