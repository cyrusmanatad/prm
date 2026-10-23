// Separate Vite test server only. No production route loads this entry point.
import '../../resources/css/app.css';
import { createApp, h } from 'vue';
import PartnerDashboard from '../../resources/js/features/portals/PartnerDashboard.vue';
import BrandDashboard from '../../resources/js/features/portals/BrandDashboard.vue';
import type { PortalData } from '../../resources/js/features/portals/types';
const brand = new URLSearchParams(location.search).get('portal') === 'brand';
const data: PortalData = {account:{name:'Bea Santos',email:'bea@example.test'},workspace:{id:'fixture',name:'Habi Home',kind:brand ? 'brand' : 'creator'},relationships:[],canManage:true,pipeline:{entries:[],counts:[],totals:[],nextCursor:null,selected:null,canWrite:true,stages:[],filters:{q:"",stage:"",due:false}}};
createApp({render:()=>h(brand ? BrandDashboard : PartnerDashboard, {data,fixture:true})}).mount('#app');
