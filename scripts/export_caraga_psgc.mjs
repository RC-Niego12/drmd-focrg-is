import { getAllRegions, getProvincesByRegion, getMunicipalitiesByProvince, getBarangaysByMunicipality } from '@aivangogh/ph-address';
const region=getAllRegions().find((row)=>row.psgcCode==='1600000000');
const rows=[{code:region.psgcCode,parent_code:null,level:'region',name:'Caraga',short_name:'CARAGA',type:'region'}];
rows.push({code:'1630400000',parent_code:region.psgcCode,level:'city_municipality',name:'City of Butuan',type:'highly_urbanized_city'});
for(const barangay of getBarangaysByMunicipality('1630400000')) rows.push({code:barangay.psgcCode,parent_code:'1630400000',level:'barangay',name:barangay.name,type:'barangay'});
for(const province of getProvincesByRegion(region.psgcCode)){
  rows.push({code:province.psgcCode,parent_code:region.psgcCode,level:'province',name:province.name,type:'province'});
  for(const municipality of getMunicipalitiesByProvince(province.psgcCode)){
    rows.push({code:municipality.psgcCode,parent_code:province.psgcCode,level:'city_municipality',name:municipality.name,type:municipality.name.toLowerCase().includes('city')?'city':'municipality'});
    for(const barangay of getBarangaysByMunicipality(municipality.psgcCode)) rows.push({code:barangay.psgcCode,parent_code:municipality.psgcCode,level:'barangay',name:barangay.name,type:'barangay'});
  }
}
process.stdout.write(JSON.stringify(rows));
